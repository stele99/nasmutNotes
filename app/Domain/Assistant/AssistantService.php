<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Ai\AiCallContext;
use App\Domain\Ai\AiModelSettings;
use App\Domain\Ai\AiUsageRecorder;
use App\Domain\User;
use App\Domain\Voice\VoiceNoteService;
use App\Repositories\SettingsRepository;
use App\Support\ValidationException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Desktop-Assistant: Reines Re-Routing zu einem OpenAI-kompatiblen Dienst.
 * Der Client spricht Standard-OpenAI (chat/completions, audio/transcriptions),
 * der Server hält die Zügel: Modell und Ziel-Endpoint kommen aus der
 * Admin-Konfiguration, der API-Schlüssel bleibt in der Umgebung, und jeder
 * Aufruf wandert ins Verbrauchsbuch.
 */
final class AssistantService
{
    private const CHAT_TIMEOUT_SECONDS = 300;

    public const MAX_PAYLOAD_BYTES = 2 * 1024 * 1024;

    public const ENABLED_KEY = 'assistant_enabled';

    /** Reasoning-Aufwand des Desktop-Assistant-Chat; leer erbt den globalen Default. */
    public const REASONING_KEY = 'assistant_chat_reasoning';

    /** Zulässige Aufnahmeformate: Endung => MIME-Typen, die Clients melden. */
    private const AUDIO_FORMATS = [
        'webm' => ['audio/webm', 'video/webm'],
        'mp4' => ['audio/mp4', 'video/mp4'],
        'm4a' => ['audio/m4a', 'audio/x-m4a'],
        'mp3' => ['audio/mpeg', 'audio/mp3'],
        'ogg' => ['audio/ogg', 'application/ogg'],
        'wav' => ['audio/wav', 'audio/x-wav', 'audio/wave'],
        'flac' => ['audio/flac', 'audio/x-flac'],
    ];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AiUsageRecorder $recorder,
        private readonly ?ClientInterface $http = null,
        private readonly string $apiKey = '',
        private readonly string $fallbackBaseUrl = VoiceNoteService::DEFAULT_BASE_URL,
        private readonly string $fallbackChatModel = VoiceNoteService::DEFAULT_POSTPROCESS_MODEL,
        private readonly string $tmpPath = '',
    ) {
    }

    public function settings(): AssistantSettings
    {
        // Ein gemeinsames LLM und eine gemeinsame Dienst-Adresse für alle
        // KI-Funktionen; nur der Reasoning-Aufwand ist hier Bereichssache.
        $baseUrl = rtrim($this->stringSetting(VoiceNoteService::BASE_URL_KEY, $this->fallbackBaseUrl), '/');

        return new AssistantSettings(
            enabled: $this->boolSetting(self::ENABLED_KEY, $this->apiKey !== ''),
            apiKey: trim($this->apiKey),
            baseUrl: $baseUrl,
            chatModel: $this->stringSetting(AiModelSettings::DEFAULT_MODEL_KEY, $this->fallbackChatModel),
            transcribeModel: $this->stringSetting(VoiceNoteService::TRANSCRIBE_MODEL_KEY, ''),
            transcribeLanguage: $this->stringSetting(VoiceNoteService::LANGUAGE_KEY, ''),
            transcribeMaxMb: $this->settings->getInt(VoiceNoteService::MAX_MB_KEY, 25) ?? 25,
            reasoning: $this->reasoningFor(),
        );
    }

    /** Reasoning-Aufwand des Bereichs; leer erbt den globalen Default. */
    private function reasoningFor(): string
    {
        $value = $this->settings->get(self::REASONING_KEY);

        return $value !== null && $value !== ''
            ? $value
            : ($this->settings->get(AiModelSettings::DEFAULT_REASONING_KEY) ?? '');
    }

    public function isUsable(): bool
    {
        return $this->settings()->isUsable();
    }

    /**
     * Übernimmt die im Admin-Dashboard geänderten Werte. Nur übermittelte
     * Schlüssel werden angefasst; ein geleertes Modell oder Base-URL schaltet
     * auf den gemeinsamen Default der KI-Funktionen zurück.
     *
     * @param array<string, mixed> $input
     */
    public function updateSettings(User $admin, array $input, string $ipHash): AssistantSettings
    {
        $changed = [];

        if (array_key_exists('enabled', $input)) {
            $this->settings->set(self::ENABLED_KEY, filter_var($input['enabled'], FILTER_VALIDATE_BOOL) ? '1' : '0');
            $changed[] = 'enabled';
        }
        if (array_key_exists('reasoning', $input)) {
            $this->settings->set(self::REASONING_KEY, AiModelSettings::validatedReasoning($input['reasoning']));
            $changed[] = 'reasoning';
        }

        return $this->settings();
    }

    /**
     * POST /chat/completions: Das Anfrage-Objekt wandert im OpenAI-Format
     * durch; allein `model` wird serverseitig gesetzt. Bei `stream: true`
     * läuft die Antwort als SSE-Durchlauf, und der Server ergänzt
     * `stream_options.include_usage`, um den Verbrauch mitzulesen. Fehler des
     * Anbieters werden mit Statuscode und Fehlerkörper unverändert
     * weitergereicht.
     *
     * @param array<string, mixed> $payload
     */
    public function chat(User $user, array $payload): UpstreamReply
    {
        $settings = $this->requireUsableSettings();
        $streaming = ($payload['stream'] ?? false) === true;

        $payload['model'] = $settings->chatModel;
        if ($settings->reasoning !== '') {
            // Bereichseinstellung oder globaler Default schlägt den Client.
            $payload['reasoning_effort'] = $settings->reasoning;
        }
        if ($streaming) {
            $payload['stream_options'] = ['include_usage' => true];
        }

        $context = new AiCallContext($user->id, 'desktop_chat', $settings->reasoning);
        $upstream = $this->request($settings->baseUrl . '/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $settings->apiKey,
                'Accept' => 'application/json',
            ],
            'json' => $payload,
            'timeout' => $streaming ? 0 : self::CHAT_TIMEOUT_SECONDS,
            'connect_timeout' => 10,
            'read_timeout' => $streaming ? self::CHAT_TIMEOUT_SECONDS : 30,
            'http_errors' => false,
        ] + ($streaming ? ['stream' => true] : []));

        $status = $upstream->getStatusCode();
        $contentType = $upstream->getHeaderLine('Content-Type');

        if ($status < 200 || $status >= 300) {
            return $this->reply($upstream, $contentType);
        }

        if ($streaming) {
            $body = new UsageTeeStream($upstream->getBody(), function (string $text, string $tail) use ($context, $settings, $payload): void {
                $usage = self::extractStreamedUsage($tail);
                $this->recorder->record(
                    $context,
                    $settings->chatModel,
                    $usage,
                    (string) json_encode($payload['messages'] ?? '', JSON_UNESCAPED_UNICODE),
                    $text,
                );
            });

            return new UpstreamReply($status, $contentType, $body);
        }

        $body = (string) $upstream->getBody();
        $decoded = json_decode($body, true);
        $this->recorder->record(
            $context,
            $settings->chatModel,
            is_array($decoded) && is_array($decoded['usage'] ?? null) ? $decoded['usage'] : null,
            (string) json_encode($payload['messages'] ?? '', JSON_UNESCAPED_UNICODE),
            is_array($decoded) && is_string($decoded['choices'][0]['message']['content'] ?? null)
                ? $decoded['choices'][0]['message']['content']
                : null,
        );

        return new UpstreamReply($status, $contentType, Utils::streamFor($body));
    }

    /**
     * POST /audio/transcriptions: nutzt unverändert die Transkriptions-
     * einstellungen der Sprachnotizen (Modell, Sprache, Base-URL, Größenlimit)
     * und reicht die OpenAI-Antwort durch - ohne Notiz, ohne Nachbearbeitung.
     *
     * @return array{status: int, contentType: string, body: string}
     */
    public function transcribe(User $user, UploadedFileInterface $file): array
    {
        $settings = $this->requireUsableSettings();
        if ($settings->transcribeModel === '') {
            throw new ValidationException('Für die Transkription ist kein Modell konfiguriert.');
        }

        $upload = $this->storeUpload($file, $settings);
        $context = new AiCallContext($user->id, 'desktop_transcribe');
        $handle = fopen($upload['path'], 'rb');
        if ($handle === false) {
            throw AssistantServiceException::upstreamUnreachable('Die Aufnahme konnte nicht gelesen werden.');
        }

        $parts = [
            ['name' => 'file', 'contents' => $handle, 'filename' => $upload['name']],
            ['name' => 'model', 'contents' => $settings->transcribeModel],
            ['name' => 'response_format', 'contents' => 'json'],
        ];
        if ($settings->transcribeLanguage !== '') {
            $parts[] = ['name' => 'language', 'contents' => $settings->transcribeLanguage];
        }

        try {
            $upstream = $this->request($settings->baseUrl . '/audio/transcriptions', [
                'headers' => ['Authorization' => 'Bearer ' . $settings->apiKey],
                'multipart' => $parts,
                'timeout' => self::CHAT_TIMEOUT_SECONDS,
                'connect_timeout' => 10,
                'http_errors' => false,
            ]);
        } finally {
            // Guzzle liest den Datei-Strom für das Multipart-Feld selbst aus
            // und schließt ihn dabei. Ein zweites fclose() wäre in PHP 8 ein
            // TypeError - der bliebe ungefangen und beendete jede
            // Transkription mit einer Fehlerseite statt einer Antwort.
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($upload['path']);
        }

        $status = $upstream->getStatusCode();
        $contentType = $upstream->getHeaderLine('Content-Type');
        $body = (string) $upstream->getBody();

        if ($status >= 200 && $status < 300) {
            $decoded = json_decode($body, true);
            $this->recorder->record(
                $context,
                $settings->transcribeModel,
                is_array($decoded) && is_array($decoded['usage'] ?? null) ? $decoded['usage'] : null,
                null,
                is_array($decoded) && is_string($decoded['text'] ?? null) ? $decoded['text'] : null,
            );
        }

        return ['status' => $status, 'contentType' => $contentType, 'body' => $body];
    }

    /**
     * Liest aus dem letzten SSE-Daten-Chunks die Usage-Angabe; genau der
     * End-Chunk trägt sie (choices leer, usage gefüllt).
     *
     * @return array<string, mixed>|null
     */
    public static function extractStreamedUsage(string $tail): ?array
    {
        $usage = null;
        foreach (preg_split('/\r?\n/', $tail) ?: [] as $line) {
            $line = trim($line);
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $json = trim(substr($line, 5));
            if ($json === '' || $json === '[DONE]') {
                continue;
            }
            $decoded = json_decode($json, true);
            if (is_array($decoded) && is_array($decoded['usage'] ?? null)) {
                /** @var array<string, mixed> $decoded['usage'] */
                $usage = $decoded['usage'];
            }
        }

        return $usage;
    }

    /** @param array<string, mixed> $options */
    private function request(string $url, array $options): ResponseInterface
    {
        $client = $this->http ?? new Client([
            'connect_timeout' => 10,
            'http_errors' => false,
        ]);

        try {
            return $client->request('POST', $url, $options);
        } catch (\Throwable $e) {
            throw AssistantServiceException::upstreamUnreachable($e->getMessage());
        }
    }

    private function reply(ResponseInterface $upstream, string $contentType): UpstreamReply
    {
        return new UpstreamReply(
            $upstream->getStatusCode(),
            $contentType,
            Utils::streamFor((string) $upstream->getBody()),
        );
    }

    private function requireUsableSettings(): AssistantSettings
    {
        $settings = $this->settings();
        if (!$settings->enabled) {
            throw new ValidationException('Der Desktop-Assistant ist nicht freigeschaltet.');
        }
        if ($settings->apiKey === '') {
            throw new ValidationException('In der Serverkonfiguration fehlt OPENAI_KEY.');
        }
        if ($settings->chatModel === '') {
            throw new ValidationException('Für den Desktop-Assistant ist kein Modell konfiguriert.');
        }

        return $settings;
    }

    /**
     * Schreibt die Aufnahme mit passender Endung in ein temporäres Verzeichnis:
     * Die Transkription erkennt das Format über den Dateinamen.
     *
     * @return array{path: string, name: string}
     */
    private function storeUpload(UploadedFileInterface $file, AssistantSettings $settings): array
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException('Die Aufnahme wurde nicht vollständig übertragen.');
        }

        $size = $file->getSize() ?? 0;
        if ($size <= 0) {
            throw new ValidationException('Die Aufnahme ist leer.');
        }
        if ($size > $settings->maxTranscribeBytes()) {
            throw new ValidationException("Die Aufnahme ist größer als {$settings->transcribeMaxMb} MB.");
        }

        $extension = $this->extensionFor($file);

        if (!is_dir($this->tmpPath) && !mkdir($this->tmpPath, 0o770, true) && !is_dir($this->tmpPath)) {
            throw new \RuntimeException("Ablage für Aufnahmen nicht anlegbar: {$this->tmpPath}");
        }

        $name = 'assistant-' . bin2hex(random_bytes(8)) . '.' . $extension;
        $path = $this->tmpPath . '/' . $name;
        $file->moveTo($path);

        return ['path' => $path, 'name' => $name];
    }

    private function extensionFor(UploadedFileInterface $file): string
    {
        $mime = strtolower(trim(explode(';', (string) $file->getClientMediaType())[0]));

        foreach (self::AUDIO_FORMATS as $extension => $mimeTypes) {
            if (in_array($mime, $mimeTypes, true)) {
                return $extension;
            }
        }

        $clientExtension = strtolower(pathinfo((string) $file->getClientFilename(), PATHINFO_EXTENSION));
        if (array_key_exists($clientExtension, self::AUDIO_FORMATS)) {
            return $clientExtension;
        }

        throw new ValidationException('Dieses Aufnahmeformat wird nicht unterstützt.');
    }

    private function stringSetting(string $key, string $default): string
    {
        $value = $this->settings->get($key);

        return $value === null || $value === '' ? $default : $value;
    }

    private function boolSetting(string $key, bool $default): bool
    {
        $value = $this->settings->get($key);

        return $value === null || $value === '' ? $default : $value === '1';
    }
}
