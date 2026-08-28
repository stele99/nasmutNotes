<?php

declare(strict_types=1);

namespace App\Domain\Voice;

use App\Domain\Ai\AiCallContext;
use App\Domain\Ai\AiUsageRecorder;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/**
 * Schmale Anbindung der beiden OpenAI-Endpunkte, die für Sprachnotizen
 * gebraucht werden: Transkription der Aufnahme und optionale Nachbearbeitung
 * des Rohtexts durch ein Sprachmodell.
 *
 * Bewusst kein SDK: Es sind zwei Aufrufe, und Guzzle liegt über
 * league/oauth2-client ohnehin bereits im Projekt.
 *
 * Der Client ist zugleich die zentrale Engstelle für das Verbrauchsbuch
 * (ai_usage_log): Wer einen Kontext mitgibt, bekommt den Aufruf mit Modell
 * und Tokenzahlen gebucht. Ohne Kontext verhält sich der Client wie bisher.
 */
final class OpenAiClient
{
    private const TIMEOUT_SECONDS = 180;

    public function __construct(
        private readonly ?ClientInterface $http = null,
        private readonly ?AiUsageRecorder $recorder = null,
    ) {
    }

    /**
     * Die Transkription berücksichtigt nur die *letzten* 224 Tokens des
     * prompt-Feldes. Gekürzt wird deshalb von vorne - was übrig bleibt, ist
     * das, was der Anbieter auch tatsächlich liest. Die Eingabegrenze der
     * Vorlagen liegt darunter, sodass hier im Regelfall nichts wegfällt.
     */
    private const MAX_VOCABULARY_HINT_LENGTH = 600;

    /**
     * Wandelt eine Audiodatei in Text (POST /audio/transcriptions). Ist in den
     * Einstellungen eine Sprache hinterlegt, geht sie als ISO-639-1-Code mit;
     * sonst erkennt das Modell sie selbst. $vocabularyHint (aus der gewählten
     * Diktier-Vorlage) geht als "prompt" mit - Whisper nutzt es, um Aussprache
     * und Schreibweise von Fachbegriffen zu treffen.
     */
    public function transcribe(
        VoiceSettings $settings,
        string $filePath,
        string $filename,
        ?AiCallContext $context = null,
        ?string $vocabularyHint = null,
    ): string {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new VoiceServiceException('Die Aufnahme konnte nicht gelesen werden.');
        }

        $parts = [
            ['name' => 'file', 'contents' => $handle, 'filename' => $filename],
            ['name' => 'model', 'contents' => $settings->transcribeModel],
            ['name' => 'response_format', 'contents' => 'json'],
        ];
        if ($settings->language !== '') {
            $parts[] = ['name' => 'language', 'contents' => $settings->language];
        }
        $vocabulary = trim($vocabularyHint ?? '');
        if ($vocabulary !== '') {
            $parts[] = [
                'name' => 'prompt',
                'contents' => mb_substr($vocabulary, -self::MAX_VOCABULARY_HINT_LENGTH),
            ];
        }

        $payload = $this->send($settings, 'audio/transcriptions', ['multipart' => $parts], $settings->transcribeModel, $context);

        $text = $payload['text'] ?? null;
        if (!is_string($text)) {
            throw new VoiceServiceException('Die Transkription lieferte keinen Text zurück.');
        }

        return trim($text);
    }

    /**
     * Schickt Systemanweisung und Rohtext an ein Chat-Modell und erwartet ein
     * JSON-Objekt zurück (POST /chat/completions). Ohne $model greift das
     * allgemeine Nachbearbeitungsmodell aus den Einstellungen - NotesVoice
     * (FR-NVOICE) nutzt hier sein eigenes, separat konfigurierbares Modell.
     *
     * @return array<string, mixed>
     */
    public function completeJson(
        VoiceSettings $settings,
        string $systemPrompt,
        string $userPrompt,
        ?string $model = null,
        ?AiCallContext $context = null,
    ): array {
        $resolvedModel = $model ?? $settings->postprocessModel;
        $requestPayload = [
            'model' => $resolvedModel,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];
        // Reasoning-Aufwand nur bei Modellen mitsenden, die ihn erwarten -
        // der Bereich entscheidet (leer = nicht senden).
        if ($context !== null && $context->reasoningEffort !== '') {
            $requestPayload['reasoning_effort'] = $context->reasoningEffort;
        }

        $payload = $this->send($settings, 'chat/completions', [
            'json' => $requestPayload,
        ], $resolvedModel, $context);

        $content = $payload['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new VoiceServiceException('Die Nachbearbeitung lieferte keine Antwort zurück.');
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new VoiceServiceException('Die Nachbearbeitung lieferte kein gültiges JSON zurück.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function send(
        VoiceSettings $settings,
        string $path,
        array $options,
        string $model,
        ?AiCallContext $context = null,
    ): array {
        $client = $this->http ?? new Client([
            'timeout' => self::TIMEOUT_SECONDS,
            'connect_timeout' => 10,
            'http_errors' => false,
        ]);

        $url = rtrim($settings->baseUrl, '/') . '/' . $path;
        $options['headers'] = ['Authorization' => 'Bearer ' . $settings->apiKey];
        // Bei injiziertem Client (Tests) greifen die Konstruktor-Optionen nicht.
        $options['http_errors'] = false;

        try {
            $response = $client->request('POST', $url, $options);
        } catch (\Throwable $e) {
            throw new VoiceServiceException(
                'Der Sprachdienst ist nicht erreichbar: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) && is_string($decoded['error']['message'] ?? null)
                ? (string) $decoded['error']['message']
                : "HTTP {$status}";

            throw new VoiceServiceException("Der Sprachdienst hat die Anfrage abgelehnt: {$message}");
        }

        if (!is_array($decoded)) {
            throw new VoiceServiceException('Der Sprachdienst lieferte eine unlesbare Antwort.');
        }

        $this->record($context, $model, $decoded['usage'] ?? null);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function record(?AiCallContext $context, string $model, mixed $usage): void
    {
        if ($this->recorder === null) {
            return;
        }

        $this->recorder->record($context, $model, is_array($usage) ? $usage : null);
    }
}
