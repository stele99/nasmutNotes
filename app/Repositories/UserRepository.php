<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\User;
use App\Support\AdminEmails;
use PDO;

final class UserRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AdminEmails $adminEmails,
    ) {
    }

    public function findById(int $id): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findByGoogleSub(string $googleSub): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE google_sub = :sub');
        $stmt->execute(['sub' => $googleSub]);
        $row = $stmt->fetch();

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findByEmail(string $email): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function create(string $googleSub, string $email, string $name, ?string $avatarUrl): User
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (google_sub, email, name, avatar_url, created_at, last_login_at)
             VALUES (:sub, :email, :name, :avatar, :now, :now)'
        );
        $now = gmdate('Y-m-d\TH:i:s.v\Z');
        $stmt->execute([
            'sub' => $googleSub,
            'email' => $email,
            'name' => $name,
            'avatar' => $avatarUrl,
            'now' => $now,
        ]);

        $user = $this->findById((int) $this->pdo->lastInsertId());
        assert($user !== null);

        return $user;
    }

    public function updateEmail(int $id, string $email): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET email = :email WHERE id = :id');
        $stmt->execute(['email' => $email, 'id' => $id]);
    }

    public function touchLastLogin(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET last_login_at = :now WHERE id = :id');
        $stmt->execute(['now' => gmdate('Y-m-d\TH:i:s.v\Z'), 'id' => $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET is_active = :active WHERE id = :id');
        $stmt->execute(['active' => $active ? 1 : 0, 'id' => $id]);
    }

    public function updateNearbySearchRadius(int $id, float $radiusKm): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET nearby_search_radius_km = :radius WHERE id = :id'
        );
        $stmt->execute(['radius' => $radiusKm, 'id' => $id]);
    }

    public function acknowledgeInfo(int $id): string
    {
        $acknowledgedAt = gmdate('Y-m-d\TH:i:s.v\Z');
        $stmt = $this->pdo->prepare('UPDATE users SET info_acknowledged_at = :acknowledged_at WHERE id = :id');
        $stmt->execute(['acknowledged_at' => $acknowledgedAt, 'id' => $id]);

        return $acknowledgedAt;
    }

    public function updateLocationCaptureMode(int $id, string $mode): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET location_capture_mode = :mode WHERE id = :id');
        $stmt->execute(['mode' => $mode, 'id' => $id]);
    }

    /** @return User[] */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM users ORDER BY created_at DESC');
        $rows = $stmt !== false ? $stmt->fetchAll() : [];

        return array_map(fn (array $row): User => $this->hydrate($row), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): User
    {
        return User::fromRow($row, $this->adminEmails->isAdmin((string) $row['email']));
    }
}
