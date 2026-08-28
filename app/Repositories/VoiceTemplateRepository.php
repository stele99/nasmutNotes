<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class VoiceTemplateRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(?int $userId, string $name, string $instruction, string $vocabulary): int
    {
        $now = gmdate('Y-m-d\TH:i:s.v\Z');
        $stmt = $this->pdo->prepare(
            'INSERT INTO voice_templates (user_id, name, instruction, vocabulary, created_at, updated_at)
             VALUES (:user_id, :name, :instruction, :vocabulary, :now, :now)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'name' => $name,
            'instruction' => $instruction,
            'vocabulary' => $vocabulary,
            'now' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM voice_templates WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function allGlobal(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM voice_templates WHERE user_id IS NULL ORDER BY name COLLATE NOCASE'
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function allForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM voice_templates WHERE user_id = :user_id ORDER BY name COLLATE NOCASE'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /** Globale Vorlagen zuerst, danach die eigenen - für die Auswahl vor der Aufnahme. */
    /** @return array<int, array<string, mixed>> */
    public function allAvailableTo(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM voice_templates
             WHERE user_id IS NULL OR user_id = :user_id
             ORDER BY (user_id IS NULL) DESC, name COLLATE NOCASE'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    public function update(int $id, string $name, string $instruction, string $vocabulary): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE voice_templates
             SET name = :name, instruction = :instruction, vocabulary = :vocabulary, updated_at = :now
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'instruction' => $instruction,
            'vocabulary' => $vocabulary,
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM voice_templates WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function countForScope(?int $userId): int
    {
        if ($userId === null) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM voice_templates WHERE user_id IS NULL');
            $stmt->execute();

            return (int) $stmt->fetchColumn();
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM voice_templates WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }
}
