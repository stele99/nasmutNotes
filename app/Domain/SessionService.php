<?php

declare(strict_types=1);

namespace App\Domain;

use App\Repositories\SessionRepository;
use App\Repositories\UserRepository;
use App\Support\NotFoundException;

final class SessionService
{
    public const COOKIE_NAME = 'notes_session';

    public function __construct(
        private readonly SessionRepository $sessions,
        private readonly UserRepository $users,
        private readonly int $lifetimeDays,
    ) {
    }

    /**
     * Erzeugt eine neue Session und gibt das rohe (ungehashte) Cookie-Token zurück.
     */
    public function start(int $userId, ?string $userAgent, ?string $ipHash): string
    {
        $token = bin2hex(random_bytes(32));
        $this->sessions->create(
            $userId,
            $this->hash($token),
            $userAgent,
            $ipHash,
            $this->expiresAt(),
        );

        return $token;
    }

    public function resolveUser(string $rawToken): ?User
    {
        $session = $this->sessions->findActiveByTokenHash($this->hash($rawToken));
        if ($session === null) {
            return null;
        }

        $user = $this->users->findById((int) $session['user_id']);
        if ($user === null || !$user->isActive) {
            return null;
        }

        $this->sessions->touch((int) $session['id'], $this->expiresAt());

        return $user;
    }

    public function logout(string $rawToken): void
    {
        $session = $this->sessions->findActiveByTokenHash($this->hash($rawToken));
        if ($session !== null) {
            $this->sessions->revoke((int) $session['id']);
        }
    }

    public function revokeAllForUser(int $userId): void
    {
        $this->sessions->revokeAllForUser($userId);
    }

    /**
     * Aktive Sitzungen für die Übersicht in den Einstellungen - ohne Token,
     * nur mit grob erkanntem Gerät, damit ein verlorenes Handy auffindbar ist.
     *
     * @return array<int, array{id: int, device: string, created_at: string, last_seen_at: string, current: bool}>
     */
    public function listForUser(int $userId, ?string $currentRawToken): array
    {
        $currentId = $this->currentSessionId($currentRawToken);

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'device' => self::describeUserAgent(is_string($row['user_agent']) ? $row['user_agent'] : ''),
                'created_at' => (string) $row['created_at'],
                'last_seen_at' => (string) $row['last_seen_at'],
                'current' => (int) $row['id'] === $currentId,
            ],
            $this->sessions->activeForUser($userId),
        );
    }

    /** @throws NotFoundException wenn die Sitzung nicht dem Nutzer gehört oder schon beendet ist */
    public function revokeForUser(int $userId, int $sessionId): void
    {
        foreach ($this->sessions->activeForUser($userId) as $row) {
            if ((int) $row['id'] === $sessionId) {
                $this->sessions->revoke($sessionId);

                return;
            }
        }

        throw new NotFoundException('Sitzung nicht gefunden.');
    }

    /** Beendet alle Sitzungen außer der gerade benutzten. */
    public function revokeOthers(int $userId, ?string $currentRawToken): int
    {
        return $this->sessions->revokeOthersForUser($userId, $this->currentSessionId($currentRawToken) ?? 0);
    }

    /** Browser und Betriebssystem in Kurzform, z. B. „Chrome auf Android“. */
    public static function describeUserAgent(string $userAgent): string
    {
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Unbekannter Browser',
        };
        $system = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X') || str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return $system === null ? $browser : "{$browser} auf {$system}";
    }

    private function currentSessionId(?string $rawToken): ?int
    {
        if ($rawToken === null || $rawToken === '') {
            return null;
        }
        $session = $this->sessions->findActiveByTokenHash($this->hash($rawToken));

        return $session !== null ? (int) $session['id'] : null;
    }

    private function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    private function expiresAt(): string
    {
        return gmdate('Y-m-d\TH:i:s.v\Z', time() + ($this->lifetimeDays * 86400));
    }
}
