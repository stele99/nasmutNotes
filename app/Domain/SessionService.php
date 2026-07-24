<?php

declare(strict_types=1);

namespace App\Domain;

use App\Repositories\SessionRepository;
use App\Repositories\UserRepository;

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

    private function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    private function expiresAt(): string
    {
        return gmdate('Y-m-d\TH:i:s.v\Z', time() + ($this->lifetimeDays * 86400));
    }
}
