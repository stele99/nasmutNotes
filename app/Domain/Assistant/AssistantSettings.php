<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

/**
 * Laufzeit-Konfiguration des Desktop-Assistant. Der API-Schlüssel bleibt auch
 * hier ausschließlich der aus der Umgebung (OPENAI_KEY); Base-URL und Modell
 * greifen gestaffelt auf die Einstellungen der Sprachnotizen zu, solange der
 * Admin nichts Eigenes hinterlegt hat.
 */
final readonly class AssistantSettings
{
    public function __construct(
        public bool $enabled,
        public string $apiKey,
        public string $chatBaseUrl,
        public string $chatModel,
        public string $transcribeBaseUrl,
        public string $transcribeModel,
        public string $transcribeLanguage,
        public int $transcribeMaxMb,
    ) {
    }

    public function isUsable(): bool
    {
        return $this->enabled && $this->apiKey !== '' && $this->chatModel !== '';
    }

    public function maxTranscribeBytes(): int
    {
        return $this->transcribeMaxMb * 1024 * 1024;
    }

    /**
     * Ansicht für das Admin-Dashboard: Der Schlüssel wird nie ausgeliefert,
     * nur seine letzten vier Zeichen zur Wiedererkennung.
     *
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'has_api_key' => $this->apiKey !== '',
            'api_key_hint' => $this->apiKey === '' ? '' : '…' . substr($this->apiKey, -4),
            'chat_base_url' => $this->chatBaseUrl,
            'chat_model' => $this->chatModel,
            'transcribe_base_url' => $this->transcribeBaseUrl,
            'transcribe_model' => $this->transcribeModel,
            'usable' => $this->isUsable(),
        ];
    }
}
