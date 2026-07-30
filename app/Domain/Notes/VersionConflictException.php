<?php

declare(strict_types=1);

namespace App\Domain\Notes;

final class VersionConflictException extends \RuntimeException
{
    /** @param array<string, mixed> $currentContent */
    public function __construct(
        public readonly array $currentContent,
        public readonly int $currentVersion,
        public readonly ?string $currentUpdatedAt = null,
        public readonly ?string $currentEditorName = null,
        public readonly string $encryptionState = 'plain',
    ) {
        parent::__construct('Versionskonflikt beim Speichern des Notizinhalts.');
    }
}
