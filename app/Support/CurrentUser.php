<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\User;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CurrentUser
{
    /**
     * Liest den durch RequireAuthMiddleware garantierten Nutzer aus dem Request.
     */
    public static function require(Request $request): User
    {
        $user = $request->getAttribute('user');
        if (!$user instanceof User) {
            throw new \RuntimeException('Kein authentifizierter Nutzer im Request vorhanden.');
        }

        return $user;
    }
}
