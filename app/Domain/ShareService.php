<?php

declare(strict_types=1);

namespace App\Domain;

use App\Repositories\ShareRepository;
use App\Support\NotFoundException;
use App\Support\ValidationException;

final class ShareService
{
    private const PERMISSIONS = ['read', 'write', 'read_copy'];

    public function __construct(
        private readonly PageService $pages,
        private readonly ShareRepository $shares,
    ) {
    }

    /** @return array{id: int, page_id: int, permission: string, token: string, created_at: string} */
    public function create(User $user, int $pageId, string $permission): array
    {
        $page = $this->pages->findOwned($user, $pageId);
        if ($page['deleted_at'] !== null) {
            throw new NotFoundException('Diese Seite wurde gelöscht.');
        }

        if (!in_array($permission, self::PERMISSIONS, true)) {
            throw new ValidationException('Ungültige Freigabeart.');
        }

        // Die öffentliche Ansicht kennt nur Notizen und Aufgabenlisten; ein
        // freigegebenes Logbuch stünde dort leer da (FR-LOG-01).
        if ($page['type'] === 'log') {
            throw new ValidationException('Logbücher können derzeit nicht geteilt werden.');
        }

        $token = bin2hex(random_bytes(32));
        $shareId = $this->shares->create(
            (int) $page['id'],
            hash('sha256', $token),
            $permission === 'write' ? 'write' : 'read',
            $permission,
        );

        return [
            'id' => $shareId,
            'page_id' => (int) $page['id'],
            'permission' => $permission,
            'token' => $token,
            'created_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    /** @return array<string, mixed> */
    public function open(User $user, string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new NotFoundException('Freigabe nicht gefunden.');
        }

        $share = $this->shares->findActiveByTokenHash(hash('sha256', $token));
        if ($share === null) {
            throw new NotFoundException('Freigabe nicht gefunden oder abgelaufen.');
        }

        if (($share['mode'] ?? null) !== 'write') {
            throw new ValidationException('Diese Freigabe ist nicht zum gemeinsamen Bearbeiten vorgesehen.');
        }

        if ((int) $share['owner_id'] !== $user->id) {
            $this->shares->recordAccess($user->id, (int) $share['share_id']);
        }

        return $share;
    }

    /** @return array<int, array<string, mixed>> */
    public function listForPage(User $user, int $pageId): array
    {
        $page = $this->pages->findOwned($user, $pageId);

        return $this->shares->listForPage((int) $page['id']);
    }

    /** @return array<int, array{id: int|string, name: string, is_owner: int|string}> */
    public function listAcceptedWriters(User $user, int $pageId): array
    {
        $page = $this->pages->find($user, $pageId);

        return $this->shares->listAcceptedWritersForPage((int) $page['id']);
    }

    /** @return array<int, array{id: int|string, name: string, is_owner: int|string}> */
    public function listCollaborators(User $user, int $pageId): array
    {
        $page = $this->pages->find($user, $pageId);

        return $this->shares->listCollaboratorsForPage((int) $page['id']);
    }

    public function revoke(User $user, int $shareId): void
    {
        $share = $this->shares->findById($shareId);
        if ($share === null) {
            throw new NotFoundException('Freigabe nicht gefunden.');
        }

        $this->pages->findOwned($user, (int) $share['page_id']);
        $this->shares->revoke($shareId);
    }

    public function revokeAll(User $user, int $pageId): void
    {
        $page = $this->pages->findOwned($user, $pageId);
        $this->shares->revokeAllForPage((int) $page['id']);
    }

    public function leave(User $user, int $pageId): void
    {
        $page = $this->pages->find($user, $pageId);
        if (($page['is_shared'] ?? false) !== true) {
            throw new NotFoundException('Diese Seite ist nicht geteilt.');
        }

        $this->shares->leavePage($user->id, $pageId);
    }
}
