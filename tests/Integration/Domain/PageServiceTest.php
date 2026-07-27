<?php

declare(strict_types=1);

namespace Tests\Integration\Domain;

use App\Domain\PageService;
use App\Domain\User;
use App\Repositories\PageRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\ForbiddenException;
use App\Support\NotFoundException;
use App\Support\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDatabaseTrait;

final class PageServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private \PDO $pdo;
    private PageService $pages;
    private User $userA;
    private User $userB;

    protected function setUp(): void
    {
        $pdo = $this->makeDatabase();
        $this->pdo = $pdo;
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

    public function testListAddsNotePreviewAndLastEditor(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Meine Notiz', null);
        $statement = $this->pdo->prepare(
            'UPDATE note_contents SET content_text = :text, updated_by = :user WHERE page_id = :page'
        );
        $statement->execute([
            'text' => "   \n\nErste sichtbare Zeile\nZweite Zeile",
            'user' => $this->userB->id,
            'page' => (int) $page['id'],
        ]);

        $listed = $this->pages->list($this->userA, 'updated', null, false)[0];

        self::assertSame('Erste sichtbare Zeile', $listed['preview']);
        self::assertSame($this->userB->name, $listed['last_editor_name']);
        self::assertNull($listed['task_count']);
    }

    public function testNotePreviewIsShortenedForLongLines(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Lange Notiz', null);
        $statement = $this->pdo->prepare('UPDATE note_contents SET content_text = :text WHERE page_id = :page');
        $statement->execute(['text' => str_repeat('a', 300), 'page' => (int) $page['id']]);

        $listed = $this->pages->list($this->userA, 'updated', null, false)[0];

        self::assertSame(141, mb_strlen((string) $listed['preview']));
        self::assertStringEndsWith('…', (string) $listed['preview']);
    }

    public function testListCountsTasksForTaskPages(): void
    {
        $page = $this->pages->create($this->userA, 'task', 'Mein Board', null);
        $categoryStatement = $this->pdo->prepare(
            'INSERT INTO categories (page_id, name, position, created_at) VALUES (:page, :name, 0, :now)'
        );
        $categoryStatement->execute(['page' => (int) $page['id'], 'name' => 'Offen', 'now' => gmdate('Y-m-d\TH:i:s.v\Z')]);
        $categoryId = (int) $this->pdo->lastInsertId();

        $statement = $this->pdo->prepare(
            'INSERT INTO tasks (category_id, title, is_done, position) VALUES (:category, :title, :done, :position)'
        );
        foreach ([['Offen A', 0], ['Offen B', 0], ['Erledigt', 1]] as $position => [$title, $done]) {
            $statement->execute([
                'category' => (int) $categoryId,
                'title' => $title,
                'done' => $done,
                'position' => $position,
            ]);
        }

        $listed = $this->pages->list($this->userA, 'updated', null, false)[0];

        self::assertSame(3, $listed['task_count']);
        self::assertSame(2, $listed['open_task_count']);
        self::assertNull($listed['preview']);
    }

    public function testCreateNotePageAutoCreatesNoteContentRow(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Meine Notiz', null);

        self::assertSame('note', $page['type']);
        self::assertSame('Meine Notiz', $page['title']);
    }

    public function testCreateTaskPageStartsWithoutCategories(): void
    {
        $page = $this->pages->create($this->userA, 'task', 'Mein Board', null);

        self::assertSame('task', $page['type']);
        $categoryQuery = $this->pdo->prepare('SELECT COUNT(*) FROM categories WHERE page_id = :page');
        $categoryQuery->execute(['page' => (int) $page['id']]);
        self::assertSame(0, (int) $categoryQuery->fetchColumn());
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

    public function testTrashedPageRemainsReadableButCannotBeEdited(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Nur lesen', null);
        $this->pages->softDelete($this->userA, (int) $page['id']);

        self::assertFalse($this->pages->find($this->userA, (int) $page['id'])['can_edit']);

        $this->expectException(ForbiddenException::class);
        $this->pages->update($this->userA, (int) $page['id'], ['title' => 'Nicht erlaubt']);
    }

    public function testMovesMultiplePagesToTrash(): void
    {
        $first = $this->pages->create($this->userA, 'note', 'Erste', null);
        $second = $this->pages->create($this->userA, 'task', 'Zweite', null);

        $trashed = $this->pages->softDeleteMany(
            $this->userA,
            [(int) $first['id'], (int) $second['id']],
        );

        self::assertSame(2, $trashed);
        self::assertCount(0, $this->pages->list($this->userA, 'updated', null, false));
        self::assertCount(2, $this->pages->list($this->userA, 'updated', null, true));
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
