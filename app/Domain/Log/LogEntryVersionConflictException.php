<?php

declare(strict_types=1);

namespace App\Domain\Log;

/**
 * Ein Logbuch-Eintrag wurde seit dem Laden anderweitig geändert oder
 * gelöscht - die zweite Korrektur überschreibt ihn nicht still.
 */
final class LogEntryVersionConflictException extends \RuntimeException
{
    /** @param array<string, mixed>|null $currentEntry null, wenn der Eintrag inzwischen gelöscht ist */
    public function __construct(public readonly ?array $currentEntry)
    {
        parent::__construct('Der Eintrag wurde inzwischen von jemand anderem geändert.');
    }
}
