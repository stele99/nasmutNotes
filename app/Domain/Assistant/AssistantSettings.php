<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

/**
 * Laufzeit-Konfiguration des Desktop-Assistant. Der API-Schlüssel bleibt auch
 * hier ausschließlich der aus der Umgebung (OPENAI_KEY); Modell und
 * Dienst-Adresse werden zentral für alle KI-Funktionen gesetzt, nur der
 * Reasoning-Aufwand ist hier Bereichssache.
 */
final readonly class AssistantSettings
{
    public function __construct(
        public bool $enabled,
        public string $apiKey,
        public string $baseUrl,
        public string $chatModel,
        public string $transcribeModel,
        public string $transcribeLanguage,
        public int $transcribeMaxMb,
        public string $reasoning = '',
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
     * nur seine letzten vier Zeichen zur Wiedererkennung. Modell und Adresse
     * stehen unter „Gemeinsame KI-Einstellungen“ und sind hier deshalb nicht
     * enthalten.
     *
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'has_api_key' => $this->apiKey !== '',
            'api_key_hint' => $this->apiKey === '' ? '' : '…' . substr($this->apiKey, -4),
            'reasoning' => $this->reasoning,
            'usable' => $this->isUsable(),
        ];
    }
}
