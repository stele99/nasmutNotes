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
        public readonly float $nearbySearchRadiusKm = 1.0,
        public readonly ?string $infoAcknowledgedAt = null,
        public readonly ?string $locationCaptureMode = null,
    ) {
    }

    /**
     * Vorname für die persönliche Anrede. Google liefert den vollen Namen;
     * fehlt er oder steht dort nur eine E-Mail-Adresse, gibt es keinen, mit
     * dem sich jemand angesprochen fühlt - dann `null`.
     */
    public function firstName(): ?string
    {
        $name = trim($this->name);
        if ($name === '' || str_contains($name, '@')) {
            return null;
        }

        $first = explode(' ', $name)[0];

        return $first !== '' ? $first : null;
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
            nearbySearchRadiusKm: (float) ($row['nearby_search_radius_km'] ?? 1.0),
            infoAcknowledgedAt: isset($row['info_acknowledged_at'])
                ? (string) $row['info_acknowledged_at']
                : null,
            locationCaptureMode: isset($row['location_capture_mode'])
                ? (string) $row['location_capture_mode']
                : null,
        );
    }
}
