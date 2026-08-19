<?php

declare(strict_types=1);

namespace App\Domain\Voice;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/**
 * Schmale Anbindung der beiden OpenAI-Endpunkte, die für Sprachnotizen
 * gebraucht werden: Transkription der Aufnahme und optionale Nachbearbeitung
 * des Rohtexts durch ein Sprachmodell.
 *
 * Bewusst kein SDK: Es sind zwei Aufrufe, und Guzzle liegt über
 * league/oauth2-client ohnehin bereits im Projekt.
 */
final class OpenAiClient
{
    private const TIMEOUT_SECONDS = 180;

    public function __construct(private readonly ?ClientInterface $http = null)
    {
    }

    /**
     * Wandelt eine Audiodatei in Text (POST /audio/transcriptions). Ist in den
     * Einstellungen eine Sprache hinterlegt, geht sie als ISO-639-1-Code mit;
     * sonst erkennt das Modell sie selbst.
     */
    public function transcribe(
        VoiceSettings $settings,
        string $filePath,
        string $filename,
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

        $payload = $this->send($settings, 'audio/transcriptions', ['multipart' => $parts]);

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
    public function completeJson(VoiceSettings $settings, string $systemPrompt, string $userPrompt, ?string $model = null): array
    {
        $payload = $this->send($settings, 'chat/completions', [
            'json' => [
                'model' => $model ?? $settings->postprocessModel,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ],
        ]);

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
    private function send(VoiceSettings $settings, string $path, array $options): array
    {
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

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
