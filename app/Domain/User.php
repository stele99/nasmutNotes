<?php

declare(strict_types=1);

namespace App\Domain;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $googleSub,
        public readonly string $email,
        public readonly string $name,
        public readonly ?string $avatarUrl,
        public readonly bool $isActive,
        public readonly bool $isAdmin,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row, bool $isAdmin): self
    {
        return new self(
            id: (int) $row['id'],
            googleSub: (string) $row['google_sub'],
            email: (string) $row['email'],
            name: (string) $row['name'],
            avatarUrl: $row['avatar_url'] !== null ? (string) $row['avatar_url'] : null,
            isActive: ((int) $row['is_active']) === 1,
            isAdmin: $isAdmin,
        );
    }
}
