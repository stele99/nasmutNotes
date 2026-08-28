<?php

declare(strict_types=1);

namespace App\Domain;

use App\Repositories\NotebookRepository;
use App\Repositories\NotebookShareRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\NotFoundException;
use App\Support\ValidationException;
use PDO;

final class NotebookService
{
    private const COLORS = [
        '#2563eb', '#7c3aed', '#db2777', '#dc2626', '#ea580c',
        '#ca8a04', '#16a34a', '#0891b2', '#475569', '#78716c',
    ];

    private const ICONS = [
        'book-open', 'folder', 'briefcase', 'house', 'plane', 'heart',
        'lightbulb', 'laptop', 'wrench', 'utensils', 'graduation-cap', 'star',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly NotebookRepository $notebooks,
        private readonly WorkspaceRepository $workspaces,
        private readonly ?NotebookShareRepository $notebookShares = null,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function list(User $user): array
    {
        return $this->notebooks->listForWorkspace($this->workspaceIdFor($user));
    }

    /**
     * Notizbücher für die Navigationsliste: die eigenen plus alle von anderen
     * geteilten. Eigene tragen `is_shared`, sobald sie mindestens einen
     * Teilnehmer haben; geteilte zeigen den Namen ihres Eigentümers. Für
     * Freigabeziele (Kopieren, Import, Spracheingabe) bleibt `list()` auf die
     * eigenen Notizbücher beschränkt.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listWithShared(User $user): array
    {
        if ($this->notebookShares === null) {
            throw new \RuntimeException('Notizbuch-Freigaben sind nicht verfügbar.');
        }

        $counts = [];
        foreach ($this->notebookShares->countForWorkspace($this->workspaceIdFor($user)) as $row) {
            $counts[(int) $row['notebook_id']] = (int) $row['participant_count'];
        }

        $notebooks = [];
        foreach ($this->list($user) as $notebook) {
            $notebook['is_owner'] = true;
            $notebook['is_shared'] = ($counts[(int) $notebook['id']] ?? 0) > 0;
            $notebook['owner_name'] = null;
            $notebooks[] = $notebook;
        }

        foreach ($this->notebookShares->listSharedNotebooksForUser($user->id) as $notebook) {
            $notebook['is_owner'] = false;
            $notebook['is_shared'] = true;
            $notebooks[] = $notebook;
        }

        return $notebooks;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    public function create(User $user, array $input): array
    {
        $workspaceId = $this->workspaceIdFor($user);
        [$name, $nameKey] = $this->validatedName($input['name'] ?? '');
        $this->assertNameAvailable($workspaceId, $nameKey);

        return $this->notebooks->create(
            $workspaceId,
            $name,
            $nameKey,
            max(0, (int) ($input['sort_order'] ?? 0)),
            $this->validatedColor($input['color'] ?? self::COLORS[0]),
            $this->validatedIcon($input['icon'] ?? self::ICONS[0]),
        );
    }

    /** @return array<string, mixed> */
    public function findOrCreate(User $user, string $name): array
    {
        $workspaceId = $this->workspaceIdFor($user);
        [$name, $nameKey] = $this->validatedName($name);

        return $this->notebooks->findByNameKey($workspaceId, $nameKey)
            ?? $this->notebooks->create($workspaceId, $name, $nameKey, 0);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    public function update(User $user, int $notebookId, array $input): array
    {
        $workspaceId = $this->workspaceIdFor($user);
        $notebook = $this->requireOwned($notebookId, $workspaceId);
        $fields = [];
        if (array_key_exists('name', $input)) {
            [$name, $nameKey] = $this->validatedName($input['name']);
            $existing = $this->notebooks->findByNameKey($workspaceId, $nameKey);
            if ($existing !== null && (int) $existing['id'] !== $notebookId) {
                throw new ValidationException('Ein Notizbuch mit diesem Namen existiert bereits.');
            }
            $fields['name'] = $name;
            $fields['name_key'] = $nameKey;
        }
        if (array_key_exists('sort_order', $input)) {
            $fields['sort_order'] = max(0, (int) $input['sort_order']);
        }
        if (array_key_exists('color', $input)) {
            $fields['color'] = $this->validatedColor($input['color']);
        }
        if (array_key_exists('icon', $input)) {
            $fields['icon'] = $this->validatedIcon($input['icon']);
        }
        // Ausblenden betrifft allein die Notizbuchliste - die Seiten des
        // Notizbuchs bleiben auffindbar und unter „Alle Notizen" sichtbar.
        if (array_key_exists('is_hidden', $input)) {
            $fields['is_hidden'] = $this->toFlag($input['is_hidden']);
        }
        $this->notebooks->updateFields((int) $notebook['id'], $fields);

        return $this->requireOwned($notebookId, $workspaceId);
    }

    public function delete(User $user, int $notebookId): void
    {
        $notebook = $this->requireOwned($notebookId, $this->workspaceIdFor($user));
        $this->pdo->beginTransaction();
        try {
            $this->notebooks->unassignPages((int) $notebook['id']);
            $this->notebooks->delete((int) $notebook['id']);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function assertExistsForWorkspace(int $notebookId, int $workspaceId): void
    {
        $this->requireOwned($notebookId, $workspaceId);
    }

    private function workspaceIdFor(User $user): int
    {
        $workspaceId = $this->workspaces->findByUserId($user->id);
        if ($workspaceId === null) {
            throw new \RuntimeException("Nutzer #{$user->id} hat keinen Workspace.");
        }

        return $workspaceId;
    }

    /** @return array<string, mixed> */
    private function requireOwned(int $notebookId, int $workspaceId): array
    {
        $notebook = $this->notebooks->findByIdForWorkspace($notebookId, $workspaceId);
        if ($notebook === null) {
            throw new NotFoundException("Notizbuch #{$notebookId} nicht gefunden.");
        }

        return $notebook;
    }

    /** @return array{string, string} */
    private function validatedName(mixed $value): array
    {
        $name = trim(is_string($value) ? $value : '');
        if ($name === '' || mb_strlen($name) > 100) {
            throw new ValidationException('Der Notizbuchname muss 1-100 Zeichen lang sein.');
        }

        return [$name, mb_strtolower($name)];
    }

    private function assertNameAvailable(int $workspaceId, string $nameKey): void
    {
        if ($this->notebooks->findByNameKey($workspaceId, $nameKey) !== null) {
            throw new ValidationException('Ein Notizbuch mit diesem Namen existiert bereits.');
        }
    }

    private function toFlag(mixed $value): int
    {
        if (!is_bool($value) && !in_array($value, [0, 1, '0', '1'], true)) {
            throw new ValidationException('Ungültiger Wert für „ausgeblendet".');
        }

        return (int) (bool) $value;
    }

    private function validatedColor(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::COLORS, true)) {
            throw new ValidationException('Ungültige Notizbuchfarbe.');
        }

        return $value;
    }

    private function validatedIcon(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::ICONS, true)) {
            throw new ValidationException('Ungültiges Notizbuchsymbol.');
        }

        return $value;
    }
}
