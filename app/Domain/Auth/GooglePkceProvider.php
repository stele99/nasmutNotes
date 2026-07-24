<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use League\OAuth2\Client\Provider\Google;

/**
 * league/oauth2-client aktiviert PKCE nur, wenn getPkceMethod() einen Wert liefert;
 * die Basisklasse gibt immer null zurück. FR-AUTH-01 verlangt PKCE zwingend.
 */
final class GooglePkceProvider extends Google
{
    protected function getPkceMethod(): string
    {
        return self::PKCE_METHOD_S256;
    }
}
