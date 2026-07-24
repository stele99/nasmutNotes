<?php

declare(strict_types=1);

namespace App\Domain;

use App\Repositories\CategoryRepository;
use App\Repositories\TaskRepository;
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

    /** @return array<string, mixed> */
    public function createTask(
        User $user,
        int $categoryId,
        string $title,
        ?string $description,
        ?string $responsible,
        ?string $link,
        bool $isDone = false,
    ): array {
        $category = $this->resolveOwnedCategory($user, $categoryId);

        return $this->tasks->create(
            (int) $category['id'],
            $this->validateTitle($title),
            $this->validateDescription($description),
            $this->validateResponsible($responsible),
            $this->validateLink($link),
            $isDone,
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateTask(User $user, int $taskId, array $input): array
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

        $this->tasks->update((int) $task['id'], $fields);

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
