<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

final class Migrator
{
    /**
     * Marker in der ersten Zeile einer Migration: Sie wird dann ohne umgebende
     * Transaktion ausgeführt und verwaltet ihre eigene.
     *
     * Nötig für den Umbau einer Tabelle nach dem offiziellen SQLite-Verfahren:
     * Dabei muss `PRAGMA foreign_keys = OFF` gelten - sonst räumte das DROP der
     * alten Tabelle über ON DELETE CASCADE deren Kindzeilen mit weg -, und
     * dieses PRAGMA bleibt innerhalb einer Transaktion wirkungslos.
     */
    private const NO_TRANSACTION_MARKER = 'migrator:no-transaction';

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

            if (str_contains($sql, self::NO_TRANSACTION_MARKER)) {
                $this->applyWithoutTransaction($name, $sql);
            } else {
                $this->applyInTransaction($name, $sql);
            }

            $newlyApplied[] = $name;
        }

        return $newlyApplied;
    }

    private function applyInTransaction(string $name, string $sql): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec($sql);
            $this->recordMigration($name);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw new \RuntimeException("Migration fehlgeschlagen: {$name}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Die Migration bringt ihre eigene Transaktion mit. Scheitert sie, bleibt
     * anders als sonst möglicherweise ein Teil angewendet - deshalb ist der
     * Marker Migrationen vorbehalten, die ohne ihn gar nicht gehen.
     */
    private function applyWithoutTransaction(string $name, string $sql): void
    {
        try {
            $this->pdo->exec($sql);
            $this->recordMigration($name);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            // Ein abgebrochener Umbau darf die Fremdschlüssel nicht aus lassen.
            $this->pdo->exec('PRAGMA foreign_keys = ON');

            throw new \RuntimeException("Migration fehlgeschlagen: {$name}: " . $e->getMessage(), 0, $e);
        }
    }

    private function recordMigration(string $name): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO migrations (name, applied_at) VALUES (:name, :applied_at)'
        );
        $stmt->execute([
            'name' => $name,
            'applied_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
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
