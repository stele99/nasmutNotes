<?php

declare(strict_types=1);

namespace App\Domain;

use App\Repositories\AuditLogRepository;
use App\Repositories\InviteRepository;
use App\Support\Env;
use App\Support\ForbiddenException;
use App\Support\NotFoundException;
use App\Support\ValidationException;

/**
 * Gemeinsame Einladungslogik für Admin- und Nutzerbereich (FR-INV-01/09).
 * Nutzer dürfen ausschließlich ihre eigenen Einladungen sehen und widerrufen,
 * Admins alle.
 */
final class InviteService
{
    private const MAX_USES_LIMIT = 50;
    private const MAX_TTL_DAYS = 365;

    public function __construct(
        private readonly InviteRepository $invites,
        private readonly AuditLogRepository $auditLog,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function listFor(User $user, bool $all): array
    {
        $rows = $all ? $this->invites->all() : $this->invites->allForCreator($user->id);

        return array_map([$this, 'serialize'], $rows);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: int, token: string, invite_url: string, expires_at: string}
     */
    public function create(User $user, array $input, string $ipHash): array
    {
        $email = isset($input['email']) && $input['email'] !== '' ? trim((string) $input['email']) : null;
        $note = isset($input['note']) && $input['note'] !== '' ? trim((string) $input['note']) : null;
        $maxUses = isset($input['max_uses']) ? (int) $input['max_uses'] : 1;
        $ttlDays = isset($input['ttl_days']) ? (int) $input['ttl_days'] : Env::int('INVITE_TTL_DAYS', 7);

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException('Ungültige E-Mail-Adresse.');
        }
        if ($note !== null && mb_strlen($note) > 200) {
            throw new ValidationException('Die Notiz darf maximal 200 Zeichen lang sein.');
        }

        $maxUses = max(1, min(self::MAX_USES_LIMIT, $maxUses));
        $ttlDays = max(1, min(self::MAX_TTL_DAYS, $ttlDays));

        $rawToken = bin2hex(random_bytes(32));
        $expiresAt = gmdate('Y-m-d\TH:i:s.v\Z', time() + ($ttlDays * 86400));

        $id = $this->invites->create(
            hash('sha256', $rawToken),
            $email,
            $note,
            $user->id,
            $maxUses,
            $expiresAt,
        );

        $this->auditLog->log($user->id, 'invite_created', 'invite', $id, $ipHash, [
            'email' => $email,
            'max_uses' => $maxUses,
        ]);

        return [
            'id' => $id,
            'token' => $rawToken,
            'invite_url' => $this->inviteUrl($rawToken),
            'expires_at' => $expiresAt,
        ];
    }

    public function revoke(User $user, int $id, string $ipHash, bool $any): void
    {
        $invite = $this->invites->findById($id);
        if ($invite === null) {
            throw new NotFoundException('Einladung nicht gefunden.');
        }

        if (!$any && (int) $invite['created_by'] !== $user->id) {
            throw new ForbiddenException('Nur eigene Einladungen können widerrufen werden.');
        }

        $this->invites->revoke($id);
        $this->auditLog->log($user->id, 'invite_revoked', 'invite', $id, $ipHash);
    }

    /**
     * @param array<string, mixed> $invite
     * @return array<string, mixed>
     */
    public function serialize(array $invite): array
    {
        return [
            'id' => (int) $invite['id'],
            'email' => $invite['email'],
            'note' => $invite['note'],
            'status' => InviteRepository::status($invite),
            'max_uses' => (int) $invite['max_uses'],
            'used_count' => (int) $invite['used_count'],
            'expires_at' => $invite['expires_at'],
            'created_at' => $invite['created_at'],
            'created_by_email' => $invite['created_by_email'] ?? null,
        ];
    }

    private function inviteUrl(string $rawToken): string
    {
        $appUrl = rtrim(Env::get('APP_URL', '') ?? '', '/');

        return "{$appUrl}/invite/{$rawToken}";
    }
}
