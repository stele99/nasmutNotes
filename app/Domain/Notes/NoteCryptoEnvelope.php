<?php

declare(strict_types=1);

namespace App\Domain\Notes;

final class NoteCryptoEnvelope
{
    public const MAX_ENVELOPE_BYTES = 1_000_000;
    public const MAX_PLAINTEXT_BYTES = 1_000_000;

    /** @param array<string, mixed> $envelope */
    public function validate(array $envelope, int $pageId): void
    {
        $encoded = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $this->invalid();
        }
        if (strlen($encoded) > self::MAX_ENVELOPE_BYTES) {
            throw new NoteEncryptionException(
                'CONTENT_TOO_LARGE',
                'Der verschlüsselte Notizinhalt überschreitet die maximale Größe von 1 MB.',
            );
        }

        $this->assertObject($envelope, ['zk', 'binding', 'kdf', 'wrapped_key', 'payload']);
        if (($envelope['zk'] ?? null) !== 1) {
            $this->invalid();
        }

        $binding = $this->object($envelope['binding'] ?? null, ['page_id']);
        if (($binding['page_id'] ?? null) !== (string) $pageId) {
            $this->invalid();
        }

        $kdf = $this->object($envelope['kdf'] ?? null, ['algo', 'iterations', 'salt']);
        if (($kdf['algo'] ?? null) !== 'PBKDF2-HMAC-SHA256' || ($kdf['iterations'] ?? null) !== 600_000) {
            $this->invalid();
        }
        $this->decodeBase64($kdf['salt'] ?? null, 16, 16);

        $wrappedKey = $this->object($envelope['wrapped_key'] ?? null, ['algo', 'iv', 'data']);
        if (($wrappedKey['algo'] ?? null) !== 'AES-256-GCM') {
            $this->invalid();
        }
        $this->decodeBase64($wrappedKey['iv'] ?? null, 12, 12);
        $this->decodeBase64($wrappedKey['data'] ?? null, 48, 48);

        $payload = $this->object($envelope['payload'] ?? null, ['algo', 'iv', 'data']);
        if (($payload['algo'] ?? null) !== 'AES-256-GCM') {
            $this->invalid();
        }
        $this->decodeBase64($payload['iv'] ?? null, 12, 12);
        $this->decodeBase64($payload['data'] ?? null, 16, self::MAX_PLAINTEXT_BYTES + 16);
    }

    /** @param array<string, mixed> $value
     *  @param list<string> $keys
     */
    private function assertObject(array $value, array $keys): void
    {
        if (array_is_list($value)) {
            $this->invalid();
        }

        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            $this->invalid();
        }
    }

    /** @param list<string> $keys
     *  @return array<string, mixed>
     */
    private function object(mixed $value, array $keys): array
    {
        if (!is_array($value)) {
            $this->invalid();
        }
        $this->assertObject($value, $keys);

        return $value;
    }

    private function decodeBase64(mixed $value, int $minimumBytes, int $maximumBytes): string
    {
        if (
            !is_string($value)
            || $value === ''
            || preg_match('/^(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?$/D', $value) !== 1
        ) {
            $this->invalid();
        }

        $decoded = base64_decode($value, true);
        if (
            $decoded === false
            || base64_encode($decoded) !== $value
            || strlen($decoded) < $minimumBytes
            || strlen($decoded) > $maximumBytes
        ) {
            $this->invalid();
        }

        return $decoded;
    }

    private function invalid(): never
    {
        throw new NoteEncryptionException(
            'INVALID_CRYPTO_ENVELOPE',
            'Der Krypto-Umschlag ist ungültig.',
        );
    }
}
