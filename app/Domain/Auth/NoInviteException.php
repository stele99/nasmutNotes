<?php

declare(strict_types=1);

namespace App\Domain\Auth;

final class NoInviteException extends AuthException
{
    public function __construct()
    {
        parent::__construct('Für diese Anmeldung wird ein gültiges Invite benötigt.');
    }
}
