<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * Werhatung eines einzelnen KI-Aufrufs: Nutzende Person und die Funktion, die
 * den Aufruf ausgelöst hat. Wandert von den Services durch den OpenAiClient
 * bis zum Verbrauchsbuch - ohne sie würde nichts zugeordnet.
 */
final readonly class AiCallContext
{
    public function __construct(
        public int $userId,
        public string $feature,
    ) {
    }
}
