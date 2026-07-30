<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Notes;

use App\Domain\Notes\NoteCryptoEnvelope;
use App\Domain\Notes\NoteEncryptionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NoteCryptoEnvelopeTest extends TestCase
{
    private NoteCryptoEnvelope $validator;

    protected function setUp(): void
    {
        $this->validator = new NoteCryptoEnvelope();
    }

    public function testAcceptsExactVersionOneEnvelope(): void
    {
        $this->validator->validate($this->envelope(123), 123);

        self::addToAssertionCount(1);
    }

    /** @param callable(array<string, mixed>): void $mutate */
    #[DataProvider('invalidEnvelopeProvider')]
    public function testRejectsInvalidEnvelope(callable $mutate): void
    {
        $envelope = $this->envelope(123);
        $mutate($envelope);

        try {
            $this->validator->validate($envelope, 123);
            self::fail('Ungültiger Umschlag wurde akzeptiert.');
        } catch (NoteEncryptionException $exception) {
            self::assertSame('INVALID_CRYPTO_ENVELOPE', $exception->errorCode);
        }
    }

    /** @return iterable<string, array{callable(array<string, mixed>): void}> */
    public static function invalidEnvelopeProvider(): iterable
    {
        yield 'unknown field' => [static function (array &$value): void {
            $value['extra'] = true;
        }];
        yield 'wrong page binding' => [static function (array &$value): void {
            $value['binding']['page_id'] = '0123';
        }];
        yield 'wrong iterations' => [static function (array &$value): void {
            $value['kdf']['iterations'] = 599_999;
        }];
        yield 'base64 without padding' => [static function (array &$value): void {
            $value['kdf']['salt'] = rtrim((string) $value['kdf']['salt'], '=');
        }];
        yield 'wrong iv length' => [static function (array &$value): void {
            $value['payload']['iv'] = base64_encode(str_repeat('i', 11));
        }];
        yield 'payload without tag' => [static function (array &$value): void {
            $value['payload']['data'] = base64_encode(str_repeat('x', 15));
        }];
    }

    public function testRejectsEnvelopeLargerThanOneMegabyteWithStableCode(): void
    {
        $envelope = $this->envelope(123);
        $envelope['payload']['data'] = str_repeat('A', NoteCryptoEnvelope::MAX_ENVELOPE_BYTES);

        try {
            $this->validator->validate($envelope, 123);
            self::fail('Zu großer Umschlag wurde akzeptiert.');
        } catch (NoteEncryptionException $exception) {
            self::assertSame('CONTENT_TOO_LARGE', $exception->errorCode);
        }
    }

    /** @return array<string, mixed> */
    private function envelope(int $pageId): array
    {
        return [
            'zk' => 1,
            'binding' => ['page_id' => (string) $pageId],
            'kdf' => [
                'algo' => 'PBKDF2-HMAC-SHA256',
                'iterations' => 600_000,
                'salt' => base64_encode(str_repeat('s', 16)),
            ],
            'wrapped_key' => [
                'algo' => 'AES-256-GCM',
                'iv' => base64_encode(str_repeat('w', 12)),
                'data' => base64_encode(str_repeat('k', 48)),
            ],
            'payload' => [
                'algo' => 'AES-256-GCM',
                'iv' => base64_encode(str_repeat('p', 12)),
                'data' => base64_encode(str_repeat('c', 16)),
            ],
        ];
    }
}
