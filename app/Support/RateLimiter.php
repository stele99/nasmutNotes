<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

final class RateLimiter
{
    public function __construct(private readonly PDO $pdo, private readonly bool $enabled)
    {
    }

    /**
     * Registriert einen Versuch unter $key und meldet, ob er noch innerhalb des Limits liegt.
     */
    public function attempt(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        if (!$this->enabled) {
            return true;
        }

        $now = time();

        $stmt = $this->pdo->prepare('SELECT attempt_count, window_start FROM rate_limits WHERE rate_key = :key');
        $stmt->execute(['key' => $key]);
        $row = $stmt->fetch();

        if ($row === false) {
            $this->insert($key, $now);

            return true;
        }

        $windowStart = (int) $row['window_start'];

        if ($now - $windowStart >= $windowSeconds) {
            $this->reset($key, $now);

            return true;
        }

        if ((int) $row['attempt_count'] >= $maxAttempts) {
            return false;
        }

        $this->increment($key);

        return true;
    }

    private function insert(string $key, int $now): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rate_limits (rate_key, attempt_count, window_start) VALUES (:key, 1, :now)'
        );
        $stmt->execute(['key' => $key, 'now' => (string) $now]);
    }

    private function reset(string $key, int $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE rate_limits SET attempt_count = 1, window_start = :now WHERE rate_key = :key'
        );
        $stmt->execute(['key' => $key, 'now' => (string) $now]);
    }

    private function increment(string $key): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE rate_limits SET attempt_count = attempt_count + 1 WHERE rate_key = :key'
        );
        $stmt->execute(['key' => $key]);
    }
}
