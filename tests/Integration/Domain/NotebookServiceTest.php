<?php

declare(strict_types=1);

namespace Tests\Integration\Domain;

use App\Domain\NotebookService;
use App\Domain\PageService;
use App\Domain\ShareService;
use App\Domain\User;
use App\Repositories\NotebookRepository;
use App\Repositories\PageRepository;
use App\Repositories\ShareRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\ForbiddenException;
use App\Support\NotFoundException;
use App\Support\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDatabaseTrait;

final class NotebookServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PDO $pdo;
    private PageService $pages;
    private NotebookService $notebooks;
    private ShareService $shares;
    private WorkspaceRepository $workspaces;
    private User $userA;
    private User $userB;

    protected function setUp(): void
    {
        $this->pdo = $this->makeDatabase();
        $this->workspaces = new WorkspaceRepository($this->pdo);
        $notebookRepository = new NotebookRepository($this->pdo);
        $shareRepository = new ShareRepository($this->pdo);
        $this->notebooks = new NotebookService($this->pdo, $notebookRepository, $this->workspaces);
        $this->pages = new PageService(new PageRepository($this->pdo), $this->workspaces, $shareRepository, $this->notebooks);
        $this->shares = new ShareService($this->pages, $shareRepository);
        $this->userA = $this->makeUser('a@example.com');
        $this->userB = $this->makeUser('b@example.com');
    }

    public function testListsPageCountsAndUnassignedCollection(): void
    {
        $notebook = $this->notebooks->create($this->userA, ['name' => 'Arbeitsnotizen']);
        $assigned = $this->pages->create($this->userA, 'note', 'Im Notizbuch', null, (int) $notebook['id']);
        $unassigned = $this->pages->create($this->userA, 'task', 'Ohne Notizbuch', null);
        $this->pages->softDelete($this->userA, (int) $assigned['id']);

        $listed = $this->notebooks->list($this->userA);
        $unassignedPages = $this->pages->list($this->userA, 'updated', null, false, null, 'unassigned');

        self::assertSame(1, (int) $listed[0]['page_count']);
        self::assertSame('Arbeitsnotizen', $assigned['notebook_name']);
        self::assertSame([(int) $unassigned['id']], array_map(static fn (array $page): int => (int) $page['id'], $unassignedPages));
    }

    public function testDeleteUnassignsActiveAndTrashedPages(): void
    {
        $notebook = $this->notebooks->create($this->userA, ['name' => 'Archiv']);
        $active = $this->pages->create($this->userA, 'note', 'Aktiv', null, (int) $notebook['id']);
        $trashed = $this->pages->create($this->userA, 'note', 'Papierkorb', null, (int) $notebook['id']);
        $this->pages->softDelete($this->userA, (int) $trashed['id']);

        $this->notebooks->delete($this->userA, (int) $notebook['id']);

        self::assertNull($this->pages->findOwned($this->userA, (int) $active['id'])['notebook_id']);
        self::assertNull($this->pages->findOwned($this->userA, (int) $trashed['id'])['notebook_id']);
        self::assertSame([], $this->notebooks->list($this->userA));
    }

    public function testNotebookMustBelongToPageOwnerAndNamesAreUniqueIgnoringCase(): void
    {
        $notebook = $this->notebooks->create($this->userA, ['name' => 'Privat']);
        $this->notebooks->create($this->userB, ['name' => 'Fremd']);

        try {
            $this->pages->create($this->userB, 'note', 'Nicht erlaubt', null, (int) $notebook['id']);
            self::fail('Expected a missing notebook exception.');
        } catch (NotFoundException) {
        }

        $this->expectException(ValidationException::class);
        $this->notebooks->create($this->userA, ['name' => 'privat']);
    }

    public function testSharedWriterCannotChangeNotebook(): void
    {
        $notebook = $this->notebooks->create($this->userA, ['name' => 'Eigentum']);
        $page = $this->pages->create($this->userA, 'note', 'Geteilt', null);
        $share = $this->shares->create($this->userA, (int) $page['id'], 'write');
        $this->shares->open($this->userB, $share['token']);

        $this->expectException(ForbiddenException::class);
        $this->pages->update($this->userB, (int) $page['id'], ['notebook_id' => (int) $notebook['id']]);
    }

    public function testSharedCollectionDoesNotExposeOwnerOrganization(): void
    {
        $notebook = $this->notebooks->create($this->userA, ['name' => 'Privat']);
        $page = $this->pages->create($this->userA, 'note', 'Freigabe', null, (int) $notebook['id']);
        $this->pages->update($this->userA, (int) $page['id'], ['is_favorite' => true]);
        $share = $this->shares->create($this->userA, (int) $page['id'], 'read');
        $this->shares->open($this->userB, $share['token']);

        $shared = $this->pages->list($this->userB, 'updated', null, false, null, 'shared');

        self::assertCount(1, $shared);
        self::assertTrue($shared[0]['is_shared']);
        self::assertNull($shared[0]['notebook_id']);
        self::assertSame(0, $shared[0]['is_favorite']);
    }

    public function testCreatesAndUpdatesNotebookAppearance(): void
    {
        $notebook = $this->notebooks->create($this->userA, [
            'name' => 'Reisen',
            'color' => '#0891b2',
            'icon' => 'plane',
        ]);

        self::assertSame('#0891b2', $notebook['color']);
        self::assertSame('plane', $notebook['icon']);

        $updated = $this->notebooks->update($this->userA, (int) $notebook['id'], [
            'color' => '#16a34a',
            'icon' => 'house',
        ]);

        self::assertSame('#16a34a', $updated['color']);
        self::assertSame('house', $updated['icon']);
    }

    public function testRejectsUnknownNotebookAppearance(): void
    {
        $this->expectException(ValidationException::class);
        $this->notebooks->create($this->userA, [
            'name' => 'Ungültig',
            'color' => '#000000',
            'icon' => 'X',
        ]);
    }

    public function testWorkspaceStatsCountActiveContentAndFiles(): void
    {
        $notebook = $this->notebooks->create($this->userA, ['name' => 'Statistik']);
        $note = $this->pages->create($this->userA, 'note', 'Notiz', null, (int) $notebook['id']);
        $taskPage = $this->pages->create($this->userA, 'task', 'Aufgaben', null, (int) $notebook['id']);
        $trashed = $this->pages->create($this->userA, 'note', 'Papierkorb', null, (int) $notebook['id']);
        $this->pages->softDelete($this->userA, (int) $trashed['id']);

        $this->pdo->prepare('INSERT INTO categories (page_id, name) VALUES (:page_id, :name)')
            ->execute(['page_id' => (int) $taskPage['id'], 'name' => 'Offen']);
        $categoryId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO tasks (category_id, title) VALUES (:category_id, :title)')
            ->execute(['category_id' => $categoryId, 'title' => 'Erste Aufgabe']);
        $this->pdo->prepare('INSERT INTO tasks (category_id, title) VALUES (:category_id, :title)')
            ->execute(['category_id' => $categoryId, 'title' => 'Zweite Aufgabe']);
        $this->pdo->prepare(
            "INSERT INTO note_attachments
                (page_id, token_hash, storage_name, mime_type, byte_size, width, height)
             VALUES (:page_id, 'image-token', 'image-file', 'image/png', 10, 1, 1)"
        )->execute(['page_id' => (int) $note['id']]);
        $this->pdo->prepare(
            "INSERT INTO page_attachments
                (page_id, token_hash, storage_name, original_name, mime_type, byte_size)
             VALUES (:page_id, 'file-token', 'stored-file', 'datei.txt', 'text/plain', 10)"
        )->execute(['page_id' => (int) $note['id']]);

        $stats = $this->pages->workspaceStats($this->userA);
        self::assertSame(1, $stats['notebooks']);
        self::assertSame(2, $stats['pages']);
        self::assertSame(2, $stats['tasks']);
        self::assertSame(2, $stats['files']);
        self::assertGreaterThanOrEqual(20, $stats['storage_bytes']);
        self::assertSame((int) $note['id'], $stats['top_items'][0]['id']);
        self::assertSame('Notiz', $stats['top_items'][0]['title']);
        self::assertGreaterThanOrEqual(20, $stats['top_items'][0]['bytes']);
    }

    public function testMovesMultiplePagesToNotebook(): void
    {
        $notebook = $this->notebooks->create($this->userA, ['name' => 'Ziel']);
        $first = $this->pages->create($this->userA, 'note', 'Erste', null);
        $second = $this->pages->create($this->userA, 'task', 'Zweite', null);

        $moved = $this->pages->moveMany(
            $this->userA,
            [(int) $first['id'], (int) $second['id']],
            (int) $notebook['id'],
        );

        self::assertSame(2, $moved);
        self::assertSame((int) $notebook['id'], (int) $this->pages->findOwned($this->userA, (int) $first['id'])['notebook_id']);
        self::assertSame((int) $notebook['id'], (int) $this->pages->findOwned($this->userA, (int) $second['id'])['notebook_id']);
    }

    private function makeUser(string $email): User
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (google_sub, email, name, created_at) VALUES (:sub, :email, :name, :now)'
        );
        $stmt->execute(['sub' => $email, 'email' => $email, 'name' => $email, 'now' => gmdate('Y-m-d\TH:i:s.v\Z')]);
        $id = (int) $this->pdo->lastInsertId();
        $this->workspaces->createForUser($id);

        return new User($id, $email, $email, $email, null, true, false);
    }
}
