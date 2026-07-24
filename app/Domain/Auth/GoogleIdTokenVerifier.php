<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;

final class GoogleIdTokenVerifier implements IdTokenVerifierInterface
{
    private const CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    private const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];
    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly string $clientId,
        private readonly ?string $hostedDomain,
        private readonly string $cacheFile,
        private readonly ?Client $httpClient = null,
    ) {
    }

    public function verify(string $idToken, string $expectedNonce): GoogleClaims
    {
        $keySet = JWK::parseKeySet($this->fetchCerts());

        try {
            $payload = (array) JWT::decode($idToken, $keySet);
        } catch (\Throwable $e) {
            throw new AuthException('ID-Token-Verifikation fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }

        $this->assertClaims($payload, $expectedNonce);

        return new GoogleClaims(
            sub: (string) $payload['sub'],
            email: (string) $payload['email'],
            emailVerified: (bool) ($payload['email_verified'] ?? false),
            name: (string) ($payload['name'] ?? ''),
            picture: isset($payload['picture']) ? (string) $payload['picture'] : null,
            hostedDomain: isset($payload['hd']) ? (string) $payload['hd'] : null,
        );
    }

    /** @param array<string, mixed> $payload */
    private function assertClaims(array $payload, string $expectedNonce): void
    {
        if (!isset($payload['iss']) || !in_array($payload['iss'], self::ISSUERS, true)) {
            throw new AuthException('Ungültiger Issuer im ID-Token.');
        }

        if (!isset($payload['aud']) || $payload['aud'] !== $this->clientId) {
            throw new AuthException('Ungültige Audience im ID-Token.');
        }

        $nonce = (string) ($payload['nonce'] ?? '');
        if ($nonce === '' || !hash_equals($expectedNonce, $nonce)) {
            throw new AuthException('Ungültige Nonce im ID-Token.');
        }

        if (($payload['email_verified'] ?? false) !== true) {
            throw new AuthException('E-Mail-Adresse bei Google nicht verifiziert.');
        }

        if ($this->hostedDomain !== null && $this->hostedDomain !== '') {
            $hd = (string) ($payload['hd'] ?? '');
            if ($hd !== $this->hostedDomain) {
                throw new AuthException('Google-Konto gehört nicht zur zulässigen Domain.');
            }
        }
    }

    /** @return array<string, mixed> */
    private function fetchCerts(): array
    {
        if (is_file($this->cacheFile) && (time() - filemtime($this->cacheFile)) < self::CACHE_TTL_SECONDS) {
            $cached = json_decode((string) file_get_contents($this->cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $client = $this->httpClient ?? new Client();
        $response = $client->get(self::CERTS_URL);
        $body = (string) $response->getBody();

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new AuthException('Google-JWKS konnte nicht geladen werden.');
        }

        @file_put_contents($this->cacheFile, $body);

        return $decoded;
    }
}
