<?php

declare(strict_types=1);

namespace App\Domain;

use App\Repositories\PageRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\NotFoundException;
use App\Support\ValidationException;

final class PageService
{
    private const TYPES = ['note', 'task'];
    private const VIEWS = ['board', 'list'];

    public function __construct(
        private readonly PageRepository $pages,
        private readonly WorkspaceRepository $workspaces,
    ) {
    }

    public function workspaceIdFor(User $user): int
    {
        $workspaceId = $this->workspaces->findByUserId($user->id);
        if ($workspaceId === null) {
            throw new \RuntimeException("Nutzer #{$user->id} hat keinen Workspace.");
        }

        return $workspaceId;
    }

    /** @return array<string, mixed> */
    public function create(User $user, string $type, string $title, ?string $icon): array
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new ValidationException('Ungültiger Seitentyp.');
        }

        $title = $this->validateTitle($title);
        $icon = $this->validateIcon($icon);

        return $this->pages->create($this->workspaceIdFor($user), $type, $title, $icon);
    }

    /** @return array<int, array<string, mixed>> */
    public function list(User $user, string $sort, ?string $typeFilter, bool $trashed): array
    {
        return $this->pages->listForWorkspace($this->workspaceIdFor($user), $sort, $typeFilter, $trashed);
    }

    /** @return array<string, mixed> */
    public function find(User $user, int $pageId): array
    {
        $page = $this->pages->findByIdForWorkspace($pageId, $this->workspaceIdFor($user));
        if ($page === null) {
            throw new NotFoundException("Seite #{$pageId} nicht gefunden.");
        }

        return $page;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(User $user, int $pageId, array $input): array
    {
        $page = $this->find($user, $pageId);

        $fields = [];

        if (array_key_exists('title', $input)) {
            $fields['title'] = $this->validateTitle((string) $input['title']);
        }
        if (array_key_exists('icon', $input)) {
            $fields['icon'] = $this->validateIcon($input['icon'] !== null ? (string) $input['icon'] : null);
        }
        if (array_key_exists('is_favorite', $input)) {
            $fields['is_favorite'] = ((bool) $input['is_favorite']) ? 1 : 0;
        }
        if (array_key_exists('sort_order', $input)) {
            $fields['sort_order'] = max(0, (int) $input['sort_order']);
        }
        if (array_key_exists('default_view', $input)) {
            if (!in_array($input['default_view'], self::VIEWS, true)) {
                throw new ValidationException('Ungültige Ansicht.');
            }
            $fields['default_view'] = $input['default_view'];
        }

        $this->pages->updateFields((int) $page['id'], $fields);

        return $this->find($user, $pageId);
    }

    public function softDelete(User $user, int $pageId): void
    {
        $page = $this->find($user, $pageId);
        $this->pages->softDelete((int) $page['id']);
    }

    public function restore(User $user, int $pageId): void
    {
        $page = $this->find($user, $pageId);
        $this->pages->restore((int) $page['id']);
    }

    public function purge(User $user, int $pageId): void
    {
        $page = $this->find($user, $pageId);
        $this->pages->purge((int) $page['id']);
    }

    private function validateTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 200) {
            throw new ValidationException('Der Seitentitel muss 1–200 Zeichen lang sein.');
        }

        return $title;
    }

    private function validateIcon(?string $icon): ?string
    {
        if ($icon === null || $icon === '') {
            return null;
        }
        if (mb_strlen($icon) > 8) {
            throw new ValidationException('Das Icon darf maximal 8 Zeichen lang sein.');
        }

        return $icon;
    }
}
