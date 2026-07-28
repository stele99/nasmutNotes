<?php

declare(strict_types=1);

namespace App\Domain\Notes;

final class NoteWriteUnavailableException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            'Die Notiz wird gerade von einer anderen Änderung verarbeitet. Bitte erneut versuchen.',
            previous: $previous,
        );
    }
}
