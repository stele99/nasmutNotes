<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * Werhatung eines einzelnen KI-Aufrufs: Nutzende Person, die Funktion, die
 * den Aufruf ausgelöst hat, und der Reasoning-Aufwand des Bereichs. Wandert
 * von den Services durch den OpenAiClient bis zum Verbrauchsbuch - ohne sie
 * würde nichts zugeordnet. Der Reasoning-Aufwand greift nur bei
 * Chat-Aufrufen; leer heißt, der Parameter wird nicht mitgeschickt.
 */
final readonly class AiCallContext
{
    public function __construct(
        public int $userId,
        public string $feature,
        public string $reasoningEffort = '',
    ) {
    }
}
