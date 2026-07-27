<?php

declare(strict_types=1);

namespace App\Domain;

use App\Repositories\CategoryRepository;
use App\Repositories\TaskRepository;
use App\Support\Env;
use App\Support\NotFoundException;
use App\Support\UrlValidator;
use App\Support\ValidationException;
use PDO;

final class TaskBoardService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PageService $pages,
        private readonly CategoryRepository $categories,
        private readonly TaskRepository $tasks,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function board(User $user, int $pageId): array
    {
        $page = $this->pages->find($user, $pageId);
        $this->assertIsTaskPage($page);
        $this->pages->assertCanWrite($user, $pageId);

        $categories = $this->categories->listForPage((int) $page['id']);
        $allTasks = $this->tasks->listForPage((int) $page['id']);

        $tasksByCategory = [];
        foreach ($allTasks as $task) {
            $tasksByCategory[(int) $task['category_id']][] = $task;
        }

        return array_map(
            static fn (array $category): array => $category + [
                'tasks' => $tasksByCategory[(int) $category['id']] ?? [],
            ],
            $categories,
        );
    }

    /** @return array<string, mixed> */
    public function createCategory(User $user, int $pageId, string $name, ?string $color, ?int $wipLimit): array
    {
        $page = $this->pages->find($user, $pageId);
        $this->assertIsTaskPage($page);

        return $this->categories->create((int) $page['id'], $this->validateCategoryName($name), $color, $wipLimit);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateCategory(User $user, int $categoryId, array $input): array
    {
        $category = $this->resolveOwnedCategory($user, $categoryId);

        $fields = [];
        if (array_key_exists('name', $input)) {
            $fields['name'] = $this->validateCategoryName((string) $input['name']);
        }
        if (array_key_exists('color', $input)) {
            $fields['color'] = $input['color'] !== null ? (string) $input['color'] : null;
        }
        if (array_key_exists('position', $input)) {
            $fields['position'] = max(0, (int) $input['position']);
        }
        if (array_key_exists('wip_limit', $input)) {
            $fields['wip_limit'] = $input['wip_limit'] !== null ? max(0, (int) $input['wip_limit']) : null;
        }

        $this->categories->update((int) $category['id'], $fields);

        $updated = $this->categories->findById((int) $category['id']);
        assert($updated !== null);

        return $updated;
    }

    public function deleteCategory(User $user, int $categoryId, ?int $moveToId, bool $cascade): void
    {
        $category = $this->resolveOwnedCategory($user, $categoryId);
        $hasTasks = $this->tasks->countForCategory((int) $category['id']) > 0;

        if ($hasTasks && !$cascade && $moveToId === null) {
            throw new ValidationException(
                'Diese Kategorie enthält Tasks. Bitte "move_to" oder "cascade=1" angeben.'
            );
        }

        $this->pdo->beginTransaction();
        try {
            if ($hasTasks && $moveToId !== null) {
                $target = $this->resolveOwnedCategory($user, $moveToId);
                if ((int) $target['page_id'] !== (int) $category['page_id']) {
                    throw new ValidationException('Zielkategorie gehört zu einer anderen Seite.');
                }
                $this->tasks->moveAllToCategory((int) $category['id'], (int) $target['id']);
            } elseif ($hasTasks && $cascade) {
                $this->tasks->deleteAllInCategory((int) $category['id']);
            }

            $this->categories->delete((int) $category['id']);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws TaskDuplicateTitleException wenn im Kapitel bereits eine Aufgabe
     *         mit gleichem Titel steht und $allowDuplicate nicht gesetzt ist
     */
    public function createTask(
        User $user,
        int $categoryId,
        string $title,
        ?string $description,
        ?string $responsible,
        ?string $link,
        bool $isDone = false,
        bool $allowDuplicate = false,
    ): array {
        $category = $this->resolveOwnedCategory($user, $categoryId);
        $title = $this->validateTitle($title);

        if (!$allowDuplicate) {
            $duplicate = $this->findTaskByTitle((int) $category['id'], $title);
            if ($duplicate !== null) {
                throw new TaskDuplicateTitleException($duplicate);
            }
        }

        return $this->tasks->create(
            (int) $category['id'],
            $title,
            $this->validateDescription($description),
            $this->validateResponsible($responsible),
            $this->validateLink($link),
            $isDone,
        );
    }

    /**
     * Titelvergleich ohne Rücksicht auf Groß-/Kleinschreibung und Randleerzeichen.
     * Bewusst in PHP statt per SQL: SQLites NOCASE und LOWER() arbeiten nur auf
     * ASCII, „Änderung“ und „änderung“ gälten dort als verschieden.
     *
     * @return array<string, mixed>|null
     */
    private function findTaskByTitle(int $categoryId, string $title): ?array
    {
        $needle = mb_strtolower(trim($title));

        foreach ($this->tasks->listForCategory($categoryId) as $task) {
            if (mb_strtolower(trim((string) $task['title'])) === $needle) {
                return $task;
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    public function importTasks(User $user, int $categoryId, string $text): array
    {
        $category = $this->resolveOwnedCategory($user, $categoryId);
        $lines = preg_split('/\r\n|\r|\n/', $text);
        if ($lines === false) {
            throw new ValidationException('Der Importtext konnte nicht gelesen werden.');
        }

        $titles = [];
        foreach ($lines as $line) {
            $title = trim($line);
            if ($title !== '') {
                $titles[] = $this->validateTitle($title);
            }
        }

        $maxLines = Env::int('IMPORT_MAX_LINES', 500);
        if (count($titles) > $maxLines) {
            throw new ValidationException("Der Import ist auf {$maxLines} Aufgaben begrenzt.");
        }
        if ($titles === []) {
            throw new ValidationException('Bitte mindestens eine Aufgabe eingeben.');
        }

        $this->pdo->beginTransaction();
        try {
            $tasks = [];
            foreach ($titles as $title) {
                $tasks[] = $this->tasks->create(
                    (int) $category['id'],
                    $title,
                    null,
                    null,
                    null,
                );
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $tasks;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws TaskVersionConflictException wenn der Task seit $expectedVersion anderweitig geändert wurde
     */
    public function updateTask(User $user, int $taskId, array $input, ?int $expectedVersion = null): array
    {
        $task = $this->resolveOwnedTask($user, $taskId);

        $fields = [];
        if (array_key_exists('title', $input)) {
            $fields['title'] = $this->validateTitle((string) $input['title']);
        }
        if (array_key_exists('description', $input)) {
            $fields['description'] = $this->validateDescription(
                $input['description'] !== null ? (string) $input['description'] : null
            );
        }
        if (array_key_exists('responsible', $input)) {
            $fields['responsible'] = $this->validateResponsible(
                $input['responsible'] !== null ? (string) $input['responsible'] : null
            );
        }
        if (array_key_exists('link', $input)) {
            $fields['link'] = $this->validateLink($input['link'] !== null ? (string) $input['link'] : null);
        }
        if (array_key_exists('is_done', $input)) {
            $fields['is_done'] = ((bool) $input['is_done']) ? 1 : 0;
        }

        if (!$this->tasks->update((int) $task['id'], $fields, $expectedVersion)) {
            $current = $this->tasks->find((int) $task['id']);
            assert($current !== null);

            throw new TaskVersionConflictException($current);
        }

        $updated = $this->tasks->find((int) $task['id']);
        assert($updated !== null);

        return $updated;
    }

    public function deleteTask(User $user, int $taskId): void
    {
        $task = $this->resolveOwnedTask($user, $taskId);
        $this->tasks->delete((int) $task['id']);
    }

    /** @return array<string, mixed> */
    public function duplicateTask(User $user, int $taskId): array
    {
        $task = $this->resolveOwnedTask($user, $taskId);

        return $this->tasks->create(
            (int) $task['category_id'],
            (string) $task['title'],
            $task['description'],
            $task['responsible'],
            $task['link'],
            false,
        );
    }

    /** @return array<string, mixed> */
    public function moveTask(User $user, int $taskId, int $targetCategoryId, int $targetPosition): array
    {
        $task = $this->resolveOwnedTask($user, $taskId);
        $targetCategory = $this->resolveOwnedCategory($user, $targetCategoryId);

        if ((int) $targetCategory['page_id'] !== (int) $this->categoryPageId((int) $task['category_id'])) {
            throw new ValidationException('Zielkategorie gehört zu einer anderen Seite.');
        }

        $sourceCategoryId = (int) $task['category_id'];
        $targetCategoryId = (int) $targetCategory['id'];
        $targetPosition = max(0, $targetPosition);

        $this->pdo->beginTransaction();
        try {
            if ($sourceCategoryId === $targetCategoryId) {
                $ids = array_column($this->tasks->listForCategory($sourceCategoryId), 'id');
                $ids = array_values(array_filter($ids, static fn ($id): bool => (int) $id !== $taskId));
                array_splice($ids, min($targetPosition, count($ids)), 0, [$taskId]);
                $this->tasks->renumberCategory($sourceCategoryId, array_map('intval', $ids));
            } else {
                $sourceIds = array_column($this->tasks->listForCategory($sourceCategoryId), 'id');
                $sourceIds = array_values(array_filter($sourceIds, static fn ($id): bool => (int) $id !== $taskId));
                $this->tasks->renumberCategory($sourceCategoryId, array_map('intval', $sourceIds));

                $this->tasks->setCategory($taskId, $targetCategoryId);
                $targetIds = array_column($this->tasks->listForCategory($targetCategoryId), 'id');
                $targetIds = array_values(array_filter($targetIds, static fn ($id): bool => (int) $id !== $taskId));
                array_splice($targetIds, min($targetPosition, count($targetIds)), 0, [$taskId]);
                $this->tasks->renumberCategory($targetCategoryId, array_map('intval', $targetIds));
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $updated = $this->tasks->find($taskId);
        assert($updated !== null);

        return $updated;
    }

    /** @param array<string, mixed> $page */
    private function assertIsTaskPage(array $page): void
    {
        if ($page['type'] !== 'task') {
            throw new NotFoundException('Diese Seite ist keine Task-Seite.');
        }
    }

    /** @return array<string, mixed> */
    private function resolveOwnedCategory(User $user, int $categoryId): array
    {
        $category = $this->categories->findById($categoryId);
        if ($category === null) {
            throw new NotFoundException("Kategorie #{$categoryId} nicht gefunden.");
        }

        // Wirft NotFoundException, falls die Seite nicht dem Workspace des Nutzers gehört (IDOR-Schutz).
        $this->pages->find($user, (int) $category['page_id']);
        $this->pages->assertCanWrite($user, (int) $category['page_id']);

        return $category;
    }

    /** @return array<string, mixed> */
    private function resolveOwnedTask(User $user, int $taskId): array
    {
        $task = $this->tasks->find($taskId);
        if ($task === null) {
            throw new NotFoundException("Task #{$taskId} nicht gefunden.");
        }

        // Prüft transitiv über die Kategorie, dass die Seite dem Nutzer gehört.
        $this->resolveOwnedCategory($user, (int) $task['category_id']);

        return $task;
    }

    private function categoryPageId(int $categoryId): int
    {
        $category = $this->categories->findById($categoryId);
        assert($category !== null);

        return (int) $category['page_id'];
    }

    private function validateCategoryName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new ValidationException('Der Kategoriename muss 1–100 Zeichen lang sein.');
        }

        return $name;
    }

    private function validateTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 200) {
            throw new ValidationException('Der Task-Titel muss 1–200 Zeichen lang sein.');
        }

        return $title;
    }

    private function validateDescription(?string $description): ?string
    {
        if ($description === null || $description === '') {
            return null;
        }
        if (mb_strlen($description) > 10_000) {
            throw new ValidationException('Die Beschreibung darf maximal 10.000 Zeichen lang sein.');
        }

        return $description;
    }

    private function validateResponsible(?string $responsible): ?string
    {
        if ($responsible === null || $responsible === '') {
            return null;
        }
        $responsible = trim($responsible);
        if (mb_strlen($responsible) > 100) {
            throw new ValidationException('Der Verantwortliche darf maximal 100 Zeichen lang sein.');
        }

        return $responsible;
    }

    private function validateLink(?string $link): ?string
    {
        if ($link === null || $link === '') {
            return null;
        }
        if (!UrlValidator::isValidHttpUrl($link)) {
            throw new ValidationException('Der Link muss eine gültige http(s)-URL sein.');
        }

        return $link;
    }
}
