<?php

declare(strict_types=1);

namespace App\Domain\Notes;

final class VersionConflictException extends \RuntimeException
{
    /** @param array<string, mixed> $currentContent */
    public function __construct(
        public readonly array $currentContent,
        public readonly int $currentVersion,
    ) {
        parent::__construct('Versionskonflikt beim Speichern des Notizinhalts.');
    }
}
