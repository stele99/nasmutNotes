<?php

declare(strict_types=1);

namespace App\Domain;

final class TaskWriteUnavailableException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            'Die Aufgabenliste wird gerade von einer anderen Änderung verarbeitet. Bitte erneut versuchen.',
            previous: $previous,
        );
    }
}
