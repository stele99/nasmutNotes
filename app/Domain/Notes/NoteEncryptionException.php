<?php

declare(strict_types=1);

namespace App\Domain\Notes;

use App\Support\ValidationException;

final class NoteEncryptionException extends ValidationException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
