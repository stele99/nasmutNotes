<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Auth;

use App\Domain\Auth\OAuthFlight;
use PHPUnit\Framework\TestCase;

final class OAuthFlightTest extends TestCase
{
    private const KEY = 'test-app-key';

    public function testEncodeDecodeRoundTrip(): void
    {
        $flight = new OAuthFlight('state123', 'verifier456', 'nonce789', 'invite-token', '/s/' . str_repeat('a', 64));

        $encoded = OAuthFlight::encode($flight, self::KEY);
        $decoded = OAuthFlight::decode($encoded, self::KEY);

        self::assertNotNull($decoded);
        self::assertSame('state123', $decoded->state);
        self::assertSame('verifier456', $decoded->codeVerifier);
        self::assertSame('nonce789', $decoded->nonce);
        self::assertSame('invite-token', $decoded->inviteToken);
        self::assertSame('/s/' . str_repeat('a', 64), $decoded->returnPath);
    }

    public function testNullInviteTokenRoundTrips(): void
    {
        $flight = new OAuthFlight('s', 'v', 'n', null);
        $decoded = OAuthFlight::decode(OAuthFlight::encode($flight, self::KEY), self::KEY);

        self::assertNotNull($decoded);
        self::assertNull($decoded->inviteToken);
    }

    public function testTamperedPayloadIsRejected(): void
    {
        $flight = new OAuthFlight('state123', 'verifier456', 'nonce789', null);
        $encoded = OAuthFlight::encode($flight, self::KEY);

        [$payload, $signature] = explode('.', $encoded, 2);
        $tampered = base64_encode('{"state":"evil","code_verifier":"x","nonce":"y","invite_token":null,"exp":9999999999}')
            . '.' . $signature;

        self::assertNull(OAuthFlight::decode($tampered, self::KEY));
    }

    public function testWrongKeyIsRejected(): void
    {
        $flight = new OAuthFlight('state123', 'verifier456', 'nonce789', null);
        $encoded = OAuthFlight::encode($flight, self::KEY);

        self::assertNull(OAuthFlight::decode($encoded, 'wrong-key'));
    }

    public function testMalformedCookieIsRejected(): void
    {
        self::assertNull(OAuthFlight::decode('not-a-valid-cookie-value', self::KEY));
    }
}
