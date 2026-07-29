<?php

declare(strict_types=1);

namespace App\Domain\Voice;

/**
 * Laufzeit-Konfiguration der Sprachnotizen (FR-VOICE-01..05). Die Werte kommen
 * aus app_settings, die .env liefert nur die Anfangswerte.
 */
final readonly class VoiceSettings
{
    public function __construct(
        public bool $enabled,
        public string $apiKey,
        public string $baseUrl,
        public string $transcribeModel,
        public string $language,
        public bool $postprocessEnabled,
        public string $postprocessModel,
        public string $postprocessPrompt,
        public string $logPrompt,
        public int $maxSeconds,
        public int $maxMb,
    ) {
    }

    /** Nutzbar ist die Funktion erst mit Schlüssel - ohne ihn bleibt sie aus. */
    public function isUsable(): bool
    {
        return $this->enabled && $this->apiKey !== '';
    }

    public function maxBytes(): int
    {
        return $this->maxMb * 1024 * 1024;
    }

    /**
     * Ansicht für das Admin-Dashboard: Der Schlüssel aus der Umgebung wird nie
     * ausgeliefert, nur seine letzten vier Zeichen zur Wiedererkennung.
     *
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'has_api_key' => $this->apiKey !== '',
            'api_key_hint' => $this->apiKey === '' ? '' : '…' . substr($this->apiKey, -4),
            'base_url' => $this->baseUrl,
            'transcribe_model' => $this->transcribeModel,
            'language' => $this->language,
            'postprocess_enabled' => $this->postprocessEnabled,
            'postprocess_model' => $this->postprocessModel,
            'postprocess_prompt' => $this->postprocessPrompt,
            'log_prompt' => $this->logPrompt,
            'max_seconds' => $this->maxSeconds,
            'max_mb' => $this->maxMb,
            'usable' => $this->isUsable(),
        ];
    }
}
