<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Im Zielkapitel existiert bereits eine Aufgabe mit demselben Titel. Kein
 * Fehler im engeren Sinn: Der Aufrufer entscheidet, ob trotzdem angelegt wird
 * (FR-TASK-20).
 */
final class TaskDuplicateTitleException extends \RuntimeException
{
    /** @param array<string, mixed> $existingTask */
    public function __construct(public readonly array $existingTask)
    {
        parent::__construct('Im Kapitel existiert bereits eine Aufgabe mit diesem Titel.');
    }
}
