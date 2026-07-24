<?php

declare(strict_types=1);

namespace App\Domain\Auth;

interface IdTokenVerifierInterface
{
    /**
     * Prüft Signatur, iss, aud, exp und nonce des Google-ID-Tokens (FR-AUTH-02).
     *
     * @throws AuthException wenn das Token ungültig ist.
     */
    public function verify(string $idToken, string $expectedNonce): GoogleClaims;
}
