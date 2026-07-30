<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Geo\ReverseGeocoder;
use App\Domain\Notes\NoteEncryptionException;
use App\Repositories\PageRepository;
use App\Repositories\ShareRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\ForbiddenException;
use App\Support\NotFoundException;
use App\Support\ValidationException;

final class PageService
{
    private const TYPES = ['note', 'task', 'log'];
    private const VIEWS = ['board', 'list'];

    public function __construct(
        private readonly PageRepository $pages,
        private readonly WorkspaceRepository $workspaces,
        private readonly ?ShareRepository $shares = null,
        private readonly ?NotebookService $notebooks = null,
        private readonly ?ReverseGeocoder $geocoder = null,
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

    /**
     * @return array{
     *     notebooks: int,
     *     pages: int,
     *     tasks: int,
     *     files: int,
     *     storage_bytes: int,
     *     top_items: list<array{id: int, title: string, type: string, deleted_at: ?string, bytes: int}>
     * }
     */
    public function workspaceStats(User $user): array
    {
        return $this->pages->workspaceStats($this->workspaceIdFor($user));
    }

    /**
     * @param array<string, mixed>|null $location Rohdaten des Browsers; ungeprüft übergeben.
     * @return array<string, mixed>
     */
    public function create(
        User $user,
        string $type,
        string $title,
        ?string $icon,
        ?int $notebookId = null,
        ?array $location = null,
    ): array {
        if (!in_array($type, self::TYPES, true)) {
            throw new ValidationException('Ungültiger Seitentyp.');
        }

        $title = $this->validateTitle($title);
        $icon = $this->validateIcon($icon);

        $workspaceId = $this->workspaceIdFor($user);
        $this->validateNotebookId($notebookId, $workspaceId);

        return $this->pages->create(
            $workspaceId,
            $type,
            $title,
            $icon,
            $notebookId,
            $this->withAddress(self::validatedLocation($location)),
        );
    }

    /**
     * Ergänzt die Anschrift zum Koordinatenpaar (FR-NOTE-26). Findet die Suche
     * nichts oder ist sie abgeschaltet, bleibt der Ort ohne Beschriftung.
     *
     * @param array{lat: float, lon: float, accuracy: ?float}|null $location
     * @return array{lat: float, lon: float, accuracy: ?float, label: ?string}|null
     */
    private function withAddress(?array $location): ?array
    {
        if ($location === null) {
            return null;
        }

        return [...$location, 'label' => $this->geocoder?->lookup($location['lat'], $location['lon'])];
    }

    /**
     * Der Aufnahmeort ist freiwillig: Unbrauchbare Werte führen deshalb nicht
     * zum Fehler, sie werden schlicht verworfen (FR-NOTE-25).
     *
     * @param array<string, mixed>|null $location
     * @return array{lat: float, lon: float, accuracy: ?float}|null
     */
    public static function validatedLocation(?array $location): ?array
    {
        $lat = isset($location['lat']) && is_numeric($location['lat']) ? (float) $location['lat'] : null;
        $lon = isset($location['lon']) && is_numeric($location['lon']) ? (float) $location['lon'] : null;
        if ($lat === null || $lon === null || abs($lat) > 90 || abs($lon) > 180) {
            return null;
        }

        $accuracy = isset($location['accuracy']) && is_numeric($location['accuracy'])
            ? max(0.0, (float) $location['accuracy'])
            : null;

        return ['lat' => $lat, 'lon' => $lon, 'accuracy' => $accuracy];
    }

    /** @return array<int, array<string, mixed>> */
    public function list(
        User $user,
        string $sort,
        ?string $typeFilter,
        bool $trashed,
        ?int $notebookId = null,
        ?string $collection = null,
    ): array {
        $unassigned = $collection === 'unassigned';
        $shared = $collection === 'shared';
        if ($collection !== null && !$unassigned && !$shared) {
            throw new ValidationException('Ungültige Seitensammlung.');
        }
        if ($notebookId !== null) {
            $this->validateNotebookId($notebookId, $this->workspaceIdFor($user));
        }
        $pages = [];

        if ($shared && $this->shares !== null) {
            foreach ($this->shares->listOwnedSharedPagesForUser($user->id, $typeFilter) as $page) {
                $pages[(int) $page['id']] = $this->withAccess($page, false, null);
            }
        } elseif (!$shared) {
            $owned = $this->pages->listForWorkspace(
                $this->workspaceIdFor($user),
                $sort,
                $typeFilter,
                $trashed,
                $notebookId,
                $unassigned,
            );
            foreach ($owned as $page) {
                $pages[(int) $page['id']] = $this->withAccess($page, false, null);
            }
        }

        if (!$trashed && $notebookId === null && !$unassigned && $this->shares !== null) {
            foreach ($this->shares->listForUser($user->id, $typeFilter) as $page) {
                $pageId = (int) $page['id'];
                if (!isset($pages[$pageId])) {
                    $pages[$pageId] = $this->withAccess(
                        $page,
                        true,
                        (string) $page['share_permission'],
                    );
                }
            }
        }

        $pages = $this->withSummaries($pages);
        usort($pages, static function (array $left, array $right) use ($sort): int {
            $favoriteDifference = (int) $right['is_favorite'] <=> (int) $left['is_favorite'];
            if ($favoriteDifference !== 0) {
                return $favoriteDifference;
            }

            return match ($sort) {
                'title' => strcasecmp((string) $left['title'], (string) $right['title']),
                'created' => strcmp((string) $right['created_at'], (string) $left['created_at']),
                default => strcmp((string) $right['updated_at'], (string) $left['updated_at']),
            };
        });

        return $pages;
    }

    /**
     * Reichert die Liste um Anriss, Aufgabenzahl und letzten Bearbeiter an -
     * die Seitenliste zeigt diese Angaben als Karte je Seite (FR-WS-13).
     *
     * @param array<int, array<string, mixed>> $pages
     * @return array<int, array<string, mixed>>
     */
    private function withSummaries(array $pages): array
    {
        $pages = array_values($pages);
        if ($pages === []) {
            return $pages;
        }

        $summaries = $this->pages->summaries(array_map(
            static fn (array $page): int => (int) $page['id'],
            $pages,
        ));

        foreach ($pages as $index => $page) {
            $summary = $summaries[(int) $page['id']] ?? [];
            $pages[$index]['preview'] = ($page['is_encrypted'] ?? false) === true
                ? 'Verschlüsselte Notiz'
                : ($summary['preview'] ?? null);
            $pages[$index]['last_editor_name'] = $summary['last_editor_name'] ?? null;
            $pages[$index]['task_count'] = $summary['task_count'] ?? null;
            $pages[$index]['open_task_count'] = $summary['open_task_count'] ?? null;
            $pages[$index]['attachment_count'] = $summary['attachment_count'] ?? 0;
            $pages[$index]['log_entry_count'] = $summary['log_entry_count'] ?? null;
            $pages[$index]['latest_entry_at'] = $summary['latest_entry_at'] ?? null;
        }

        return $pages;
    }

    /** @return array<string, mixed> */
    public function find(User $user, int $pageId): array
    {
        $page = $this->pages->findByIdForWorkspace($pageId, $this->workspaceIdFor($user));
        if ($page !== null) {
            return $this->withAccess($page, false, null);
        }

        if ($this->shares !== null) {
            $sharedPage = $this->shares->findSharedPageForUser($user->id, $pageId);
            if ($sharedPage !== null) {
                return $this->withAccess(
                    $sharedPage,
                    true,
                    (string) $sharedPage['share_permission'],
                );
            }
        }

        throw new NotFoundException("Seite #{$pageId} nicht gefunden.");
    }

    /** @return array<string, mixed> */
    public function findOwned(User $user, int $pageId): array
    {
        $page = $this->pages->findByIdForWorkspace($pageId, $this->workspaceIdFor($user));
        if ($page === null) {
            throw new NotFoundException("Seite #{$pageId} nicht gefunden.");
        }

        $page['is_encrypted'] = (bool) ($page['is_encrypted'] ?? false);

        return $page;
    }

    public function assertCanWrite(User $user, int $pageId): void
    {
        $page = $this->find($user, $pageId);
        if (($page['can_edit'] ?? false) !== true) {
            throw new ForbiddenException('Diese Freigabe ist nur lesend.');
        }
    }

    /** @param array<string, mixed> $page */
    public function assertNotEncrypted(array $page): void
    {
        if ((bool) ($page['is_encrypted'] ?? false)) {
            throw new NoteEncryptionException(
                'NOTE_ENCRYPTED',
                'Diese Funktion ist für verschlüsselte Notizen nicht verfügbar.',
            );
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(User $user, int $pageId, array $input): array
    {
        $page = $this->find($user, $pageId);
        if (($page['can_edit'] ?? false) !== true) {
            throw new ForbiddenException('Diese Freigabe ist nur lesend.');
        }

        if (($page['is_shared'] ?? false) === true) {
            foreach (['is_favorite', 'sort_order', 'default_view', 'notebook_id', 'location'] as $field) {
                if (array_key_exists($field, $input)) {
                    throw new ForbiddenException('Geteilte Seiten können nicht verwaltet werden.');
                }
            }
        }

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
        if (array_key_exists('notebook_id', $input)) {
            $notebookId = $input['notebook_id'];
            if ($notebookId !== null && (!is_int($notebookId) && !(is_string($notebookId) && ctype_digit($notebookId)))) {
                throw new ValidationException('Ungültiges Notizbuch.');
            }
            $notebookId = $notebookId !== null ? (int) $notebookId : null;
            $this->validateNotebookId($notebookId, $this->workspaceIdFor($user));
            $fields['notebook_id'] = $notebookId;
        }
        // Der Ort lässt sich nachträglich setzen, verschieben und wieder
        // entfernen (FR-NOTE-25). `null` löscht ihn, ein unbrauchbarer Wert
        // wird - anders als beim Anlegen - als Fehler gemeldet: Hier hat der
        // Nutzer ihn ausdrücklich eingegeben.
        if (array_key_exists('location', $input)) {
            $location = null;
            if ($input['location'] !== null) {
                if (!is_array($input['location'])) {
                    throw new ValidationException('Ungültiger Standort.');
                }
                $location = self::validatedLocation($input['location']);
                if ($location === null) {
                    throw new ValidationException('Ungültige Koordinaten.');
                }
                $location = $this->withAddress($location);
            }

            $fields['location_lat'] = $location['lat'] ?? null;
            $fields['location_lon'] = $location['lon'] ?? null;
            $fields['location_accuracy'] = $location['accuracy'] ?? null;
            $fields['location_label'] = $location['label'] ?? null;
            $fields['location_at'] = $location === null ? null : gmdate('Y-m-d\TH:i:s.v\Z');
        }

        $this->pages->updateFields((int) $page['id'], $fields);

        return $this->find($user, $pageId);
    }

    /** @param list<int> $pageIds */
    public function moveMany(User $user, array $pageIds, ?int $notebookId): int
    {
        $pageIds = array_values(array_unique(array_filter($pageIds, static fn (int $id): bool => $id > 0)));
        if ($pageIds === [] || count($pageIds) > 200) {
            throw new ValidationException('Es müssen 1-200 Seiten ausgewählt werden.');
        }

        $workspaceId = $this->workspaceIdFor($user);
        $this->validateNotebookId($notebookId, $workspaceId);
        foreach ($pageIds as $pageId) {
            $page = $this->findOwned($user, $pageId);
            if ($page['deleted_at'] !== null) {
                throw new ValidationException('Seiten im Papierkorb können nicht verschoben werden.');
            }
        }

        return $this->pages->moveToNotebook($workspaceId, $pageIds, $notebookId);
    }

    /** @param list<int> $pageIds */
    public function softDeleteMany(User $user, array $pageIds): int
    {
        $pageIds = array_values(array_unique(array_filter($pageIds, static fn (int $id): bool => $id > 0)));
        if ($pageIds === [] || count($pageIds) > 200) {
            throw new ValidationException('Es müssen 1-200 Seiten ausgewählt werden.');
        }

        $workspaceId = $this->workspaceIdFor($user);
        foreach ($pageIds as $pageId) {
            $page = $this->findOwned($user, $pageId);
            if ($page['deleted_at'] !== null) {
                throw new ValidationException('Eine ausgewählte Seite liegt bereits im Papierkorb.');
            }
        }

        return $this->pages->softDeleteMany($workspaceId, $pageIds);
    }

    public function softDelete(User $user, int $pageId): void
    {
        $page = $this->findOwned($user, $pageId);
        $this->pages->softDelete((int) $page['id']);
    }

    public function restore(User $user, int $pageId): void
    {
        $page = $this->findOwned($user, $pageId);
        $this->pages->restore((int) $page['id']);
    }

    public function purge(User $user, int $pageId): void
    {
        $page = $this->findOwned($user, $pageId);
        $this->pages->purge((int) $page['id']);
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function withAccess(array $page, bool $isShared, ?string $permission): array
    {
        $page['is_encrypted'] = (bool) ($page['is_encrypted'] ?? false);
        $page['is_shared'] = $isShared;
        $page['share_permission'] = $permission;
        $page['can_edit'] = $page['deleted_at'] === null
            && (!$isShared || $permission === 'write');
        if ($isShared) {
            // Favoriten und Notizbücher sind persönliche Organisationsdaten des
            // Eigentümers und werden Empfängern nicht zugänglich gemacht.
            $page['is_favorite'] = 0;
            $page['notebook_id'] = null;
            $page['notebook_name'] = null;
        }

        return $page;
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

    private function validateNotebookId(?int $notebookId, int $workspaceId): void
    {
        if ($notebookId !== null) {
            if ($this->notebooks === null) {
                throw new \RuntimeException('Notizbücher sind nicht verfügbar.');
            }
            $this->notebooks->assertExistsForWorkspace($notebookId, $workspaceId);
        }
    }
}
