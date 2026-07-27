<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Zur Laufzeit änderbare Einstellungen (FR-ADM-05). Ergänzt die .env-Werte um
 * das, was der Admin ohne Deployment ändern können muss.
 */
final class SettingsRepository
{
    public const DEFAULT_STORAGE_QUOTA_MB = 'default_storage_quota_mb';

    /**
     * Obergrenze in KB, bis zu der Anhänge beim Offline-Prefetch automatisch
     * mitgeladen werden (FR-OFFLINE-06).
     */
    public const OFFLINE_ATTACHMENT_MAX_KB = 'offline_attachment_max_kb';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM app_settings WHERE key = :key');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (string) $value : null;
    }

    public function getInt(string $key, ?int $default = null): ?int
    {
        $value = $this->get($key);

        return $value !== null && $value !== '' ? (int) $value : $default;
    }

    public function set(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO app_settings (key, value, updated_at)
             VALUES (:key, :value, :now)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
        );
        $stmt->execute([
            'key' => $key,
            'value' => $value,
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
    }
}
