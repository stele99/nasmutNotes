<?php

declare(strict_types=1);

namespace App\Domain\Auth;

final class InviteInvalidException extends AuthException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Invite ungültig: {$reason}");
    }
}
