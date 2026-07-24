<?php

declare(strict_types=1);

namespace Tests\Integration\Domain;

use App\Domain\PageService;
use App\Domain\TaskBoardService;
use App\Domain\User;
use App\Repositories\CategoryRepository;
use App\Repositories\PageRepository;
use App\Repositories\TaskRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\NotFoundException;
use App\Support\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDatabaseTrait;

final class TaskBoardServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private TaskBoardService $board;
    private PageService $pages;
    private User $userA;
    private User $userB;
    private int $taskPageId;

    protected function setUp(): void
    {
        $pdo = $this->makeDatabase();
        $workspaces = new WorkspaceRepository($pdo);
        $pageRepo = new PageRepository($pdo);
        $this->pages = new PageService($pageRepo, $workspaces);
        $this->board = new TaskBoardService($pdo, $this->pages, new CategoryRepository($pdo), new TaskRepository($pdo));

        $this->userA = $this->makeUser($pdo, $workspaces, 'a@example.com');
        $this->userB = $this->makeUser($pdo, $workspaces, 'b@example.com');

        $page = $this->pages->create($this->userA, 'task', 'Board', null);
        $this->taskPageId = (int) $page['id'];
    }

    private function makeUser(PDO $pdo, WorkspaceRepository $workspaces, string $email): User
    {
        $stmt = $pdo->prepare('INSERT INTO users (google_sub, email, name, created_at) VALUES (:sub, :email, :name, :now)');
        $stmt->execute(['sub' => $email, 'email' => $email, 'name' => $email, 'now' => gmdate('Y-m-d\TH:i:s.v\Z')]);
        $id = (int) $pdo->lastInsertId();
        $workspaces->createForUser($id);

        return new User($id, $email, $email, $email, null, true, false);
    }

    private function firstCategoryId(): int
    {
        $board = $this->board->board($this->userA, $this->taskPageId);

        return (int) $board[0]['id'];
    }

    public function testTaskPageGetsThreeDefaultCategories(): void
    {
        $board = $this->board->board($this->userA, $this->taskPageId);

        self::assertCount(3, $board);
        self::assertSame(['Offen', 'In Arbeit', 'Erledigt'], array_column($board, 'name'));
        foreach ($board as $category) {
            self::assertSame([], $category['tasks']);
        }
    }

    public function testCreateTaskAppearsInCategory(): void
    {
        $categoryId = $this->firstCategoryId();
        $task = $this->board->createTask($this->userA, $categoryId, 'Erste Aufgabe', null, 'Anna', 'https://example.com');

        self::assertSame('Erste Aufgabe', $task['title']);
        self::assertSame('Anna', $task['responsible']);

        $board = $this->board->board($this->userA, $this->taskPageId);
        self::assertCount(1, $board[0]['tasks']);
    }

    public function testInvalidTaskLinkRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->board->createTask($this->userA, $this->firstCategoryId(), 'Task', null, null, 'javascript:alert(1)');
    }

    public function testTitleTooLongRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->board->createTask($this->userA, $this->firstCategoryId(), str_repeat('x', 201), null, null, null);
    }

    public function testMoveTaskBetweenCategoriesNormalizesPositions(): void
    {
        $board = $this->board->board($this->userA, $this->taskPageId);
        $openId = (int) $board[0]['id'];
        $inProgressId = (int) $board[1]['id'];

        $t1 = $this->board->createTask($this->userA, $openId, 'T1', null, null, null);
        $t2 = $this->board->createTask($this->userA, $openId, 'T2', null, null, null);
        $t3 = $this->board->createTask($this->userA, $openId, 'T3', null, null, null);

        $this->board->moveTask($this->userA, (int) $t2['id'], $inProgressId, 0);

        $board = $this->board->board($this->userA, $this->taskPageId);
        $openTasks = $board[0]['tasks'];
        $inProgressTasks = $board[1]['tasks'];

        self::assertSame([(int) $t1['id'], (int) $t3['id']], array_column($openTasks, 'id'));
        self::assertSame([0, 1], array_map('intval', array_column($openTasks, 'position')));
        self::assertSame([(int) $t2['id']], array_column($inProgressTasks, 'id'));
    }

    public function testDeleteCategoryWithTasksWithoutMoveOrCascadeFails(): void
    {
        $categoryId = $this->firstCategoryId();
        $this->board->createTask($this->userA, $categoryId, 'T1', null, null, null);

        $this->expectException(ValidationException::class);
        $this->board->deleteCategory($this->userA, $categoryId, null, false);
    }

    public function testDeleteCategoryWithCascadeRemovesTasks(): void
    {
        $board = $this->board->board($this->userA, $this->taskPageId);
        $categoryId = (int) $board[0]['id'];
        $this->board->createTask($this->userA, $categoryId, 'T1', null, null, null);

        $this->board->deleteCategory($this->userA, $categoryId, null, true);

        $board = $this->board->board($this->userA, $this->taskPageId);
        self::assertCount(2, $board);
    }

    public function testDeleteCategoryWithMoveToTransfersTasks(): void
    {
        $board = $this->board->board($this->userA, $this->taskPageId);
        $openId = (int) $board[0]['id'];
        $inProgressId = (int) $board[1]['id'];
        $task = $this->board->createTask($this->userA, $openId, 'T1', null, null, null);

        $this->board->deleteCategory($this->userA, $openId, $inProgressId, false);

        $board = $this->board->board($this->userA, $this->taskPageId);
        self::assertCount(2, $board);
        $remaining = array_filter($board, static fn (array $c): bool => (int) $c['id'] === $inProgressId);
        $remaining = array_values($remaining)[0];
        self::assertSame([(int) $task['id']], array_column($remaining['tasks'], 'id'));
    }

    public function testCrossUserCategoryAccessReturnsNotFound(): void
    {
        $categoryId = $this->firstCategoryId();

        $this->expectException(NotFoundException::class);
        $this->board->createTask($this->userB, $categoryId, 'Sneaky', null, null, null);
    }

    public function testDuplicateTaskCopiesFields(): void
    {
        $task = $this->board->createTask($this->userA, $this->firstCategoryId(), 'Original', 'Desc', 'Anna', 'https://example.com');
        $duplicate = $this->board->duplicateTask($this->userA, (int) $task['id']);

        self::assertNotSame($task['id'], $duplicate['id']);
        self::assertSame('Original', $duplicate['title']);
        self::assertSame('Anna', $duplicate['responsible']);
        self::assertFalse((bool) $duplicate['is_done']);
    }
}
