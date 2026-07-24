<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Kurzlebiger, signierter Zustand zwischen /auth/google und /auth/callback
 * (state, PKCE-Code-Verifier, Nonce, optionales Invite-Token). Da vor dem
 * Login noch keine DB-Session existiert, wird dieser Zustand in einem
 * signierten Cookie transportiert statt in der sessions-Tabelle.
 */
final class OAuthFlight
{
    public const COOKIE_NAME = 'oauth_flight';
    public const TTL_SECONDS = 600;

    public function __construct(
        public readonly string $state,
        public readonly string $codeVerifier,
        public readonly string $nonce,
        public readonly ?string $inviteToken,
    ) {
    }

    public static function encode(self $flight, string $appKey): string
    {
        $payload = json_encode([
            'state' => $flight->state,
            'code_verifier' => $flight->codeVerifier,
            'nonce' => $flight->nonce,
            'invite_token' => $flight->inviteToken,
            'exp' => time() + self::TTL_SECONDS,
        ], JSON_THROW_ON_ERROR);

        $encodedPayload = base64_encode($payload);
        $signature = self::sign($encodedPayload, $appKey);

        return $encodedPayload . '.' . $signature;
    }

    public static function decode(string $cookieValue, string $appKey): ?self
    {
        $parts = explode('.', $cookieValue, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$encodedPayload, $signature] = $parts;

        if (!hash_equals(self::sign($encodedPayload, $appKey), $signature)) {
            return null;
        }

        $json = base64_decode($encodedPayload, true);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['exp']) || (int) $data['exp'] < time()) {
            return null;
        }

        return new self(
            state: (string) $data['state'],
            codeVerifier: (string) $data['code_verifier'],
            nonce: (string) $data['nonce'],
            inviteToken: isset($data['invite_token']) ? (string) $data['invite_token'] : null,
        );
    }

    private static function sign(string $encodedPayload, string $appKey): string
    {
        return hash_hmac('sha256', $encodedPayload, $appKey);
    }
}
