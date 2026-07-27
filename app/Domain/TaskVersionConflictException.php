<?php

declare(strict_types=1);

namespace App\Domain;

final class TaskVersionConflictException extends \RuntimeException
{
    /** @param array<string, mixed> $currentTask */
    public function __construct(public readonly array $currentTask)
    {
        parent::__construct('Versionskonflikt beim Speichern der Aufgabe.');
    }
}
