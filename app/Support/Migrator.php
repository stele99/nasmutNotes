<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsPath,
    ) {
    }

    /**
     * @return string[] Namen der neu angewendeten Migrationen.
     */
    public function migrate(): array
    {
        $this->ensureMigrationsTable();

        $applied = $this->appliedMigrations();
        $files = $this->pendingFiles($applied);

        $newlyApplied = [];

        foreach ($files as $file) {
            $name = basename($file);
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new \RuntimeException("Migration konnte nicht gelesen werden: {$file}");
            }

            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec($sql);

                $stmt = $this->pdo->prepare(
                    'INSERT INTO migrations (name, applied_at) VALUES (:name, :applied_at)'
                );
                $stmt->execute([
                    'name' => $name,
                    'applied_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
                ]);

                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw new \RuntimeException("Migration fehlgeschlagen: {$name}: " . $e->getMessage(), 0, $e);
            }

            $newlyApplied[] = $name;
        }

        return $newlyApplied;
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT NOT NULL UNIQUE,
                applied_at TEXT NOT NULL
            )'
        );
    }

    /**
     * @return string[]
     */
    private function appliedMigrations(): array
    {
        $stmt = $this->pdo->query('SELECT name FROM migrations');
        if ($stmt === false) {
            return [];
        }

        return array_column($stmt->fetchAll(), 'name');
    }

    /**
     * @param string[] $applied
     * @return string[]
     */
    private function pendingFiles(array $applied): array
    {
        $all = glob(rtrim($this->migrationsPath, '/') . '/*.sql') ?: [];
        sort($all);

        return array_values(array_filter(
            $all,
            static fn (string $file): bool => !in_array(basename($file), $applied, true)
        ));
    }
}
