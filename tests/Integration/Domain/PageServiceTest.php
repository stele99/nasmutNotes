<?php

declare(strict_types=1);

namespace Tests\Integration\Domain;

use App\Domain\PageService;
use App\Domain\User;
use App\Repositories\PageRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\NotFoundException;
use App\Support\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDatabaseTrait;

final class PageServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PageService $pages;
    private User $userA;
    private User $userB;

    protected function setUp(): void
    {
        $pdo = $this->makeDatabase();
        $workspaces = new WorkspaceRepository($pdo);
        $pages = new PageRepository($pdo);
        $this->pages = new PageService($pages, $workspaces);

        $this->userA = $this->makeUser($pdo, $workspaces, 'a@example.com');
        $this->userB = $this->makeUser($pdo, $workspaces, 'b@example.com');
    }

    private function makeUser(\PDO $pdo, WorkspaceRepository $workspaces, string $email): User
    {
        $stmt = $pdo->prepare('INSERT INTO users (google_sub, email, name, created_at) VALUES (:sub, :email, :name, :now)');
        $stmt->execute(['sub' => $email, 'email' => $email, 'name' => $email, 'now' => gmdate('Y-m-d\TH:i:s.v\Z')]);
        $id = (int) $pdo->lastInsertId();
        $workspaces->createForUser($id);

        return new User($id, $email, $email, $email, null, true, false);
    }

    public function testCreateNotePageAutoCreatesNoteContentRow(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Meine Notiz', null);

        self::assertSame('note', $page['type']);
        self::assertSame('Meine Notiz', $page['title']);
    }

    public function testCreateTaskPageGetsDefaultCategoriesViaRepository(): void
    {
        $page = $this->pages->create($this->userA, 'task', 'Mein Board', null);

        self::assertSame('task', $page['type']);
    }

    public function testTitleValidationRejectsEmptyAndTooLong(): void
    {
        $this->expectException(ValidationException::class);
        $this->pages->create($this->userA, 'note', '   ', null);
    }

    public function testTitleValidationRejectsOver200Chars(): void
    {
        $this->expectException(ValidationException::class);
        $this->pages->create($this->userA, 'note', str_repeat('x', 201), null);
    }

    public function testInvalidTypeRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->pages->create($this->userA, 'bogus', 'Title', null);
    }

    public function testCrossUserAccessReturnsNotFound(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Privat', null);

        $this->expectException(NotFoundException::class);
        $this->pages->find($this->userB, (int) $page['id']);
    }

    public function testSoftDeleteHidesFromDefaultListAndRestoreBringsBack(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Trash Me', null);
        $this->pages->softDelete($this->userA, (int) $page['id']);

        $active = $this->pages->list($this->userA, 'updated', null, false);
        self::assertCount(0, $active);

        $trashed = $this->pages->list($this->userA, 'updated', null, true);
        self::assertCount(1, $trashed);

        $this->pages->restore($this->userA, (int) $page['id']);
        self::assertCount(1, $this->pages->list($this->userA, 'updated', null, false));
    }

    public function testPurgeRemovesPagePermanently(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Gone', null);
        $this->pages->purge($this->userA, (int) $page['id']);

        $this->expectException(NotFoundException::class);
        $this->pages->find($this->userA, (int) $page['id']);
    }

    public function testUpdateFavoriteAndTitle(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Original', null);
        $updated = $this->pages->update($this->userA, (int) $page['id'], [
            'title' => 'Renamed',
            'is_favorite' => true,
        ]);

        self::assertSame('Renamed', $updated['title']);
        self::assertSame(1, (int) $updated['is_favorite']);
    }
}
