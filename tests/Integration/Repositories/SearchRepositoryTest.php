<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Domain\NotebookService;
use App\Domain\NotebookShareService;
use App\Domain\PageService;
use App\Domain\User;
use App\Repositories\NotebookRepository;
use App\Repositories\NotebookShareRepository;
use App\Repositories\PageRepository;
use App\Repositories\SearchRepository;
use App\Repositories\ShareRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\AdminEmails;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDatabaseTrait;

/**
 * Die Seitenleiste sucht in der gewählten Sammlung, die Übersicht im ganzen
 * Workspace (siehe SearchRepository::search).
 */
final class SearchRepositoryTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PDO $pdo;
    private PageService $pages;
    private NotebookService $notebooks;
    private SearchRepository $search;
    private User $user;
    private int $workspaceId;

    protected function setUp(): void
    {
        $this->pdo = $this->makeDatabase();
        $workspaces = new WorkspaceRepository($this->pdo);
        $this->notebooks = new NotebookService($this->pdo, new NotebookRepository($this->pdo), $workspaces);
        $this->pages = new PageService(
            new PageRepository($this->pdo),
            $workspaces,
            new ShareRepository($this->pdo),
            $this->notebooks,
        );
        $this->search = new SearchRepository($this->pdo);

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (google_sub, email, name, created_at) VALUES (:sub, :email, :name, :now)'
        );
        $stmt->execute([
            'sub' => 'a@example.com',
            'email' => 'a@example.com',
            'name' => 'A',
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
        $userId = (int) $this->pdo->lastInsertId();
        $workspaces->createForUser($userId);

        $this->user = new User($userId, 'a@example.com', 'A', 'a@example.com', null, true, false);
        $this->workspaceId = $this->pages->workspaceIdFor($this->user);
    }

    public function testWithoutACollectionEveryPageOfTheWorkspaceIsSearched(): void
    {
        $notebook = (int) $this->notebooks->create($this->user, ['name' => 'Reisen'])['id'];
        $this->pages->create($this->user, 'note', 'Protokoll Montag', null, $notebook);
        $this->pages->create($this->user, 'note', 'Protokoll Dienstag', null);

        self::assertSame(
            ['Protokoll Dienstag', 'Protokoll Montag'],
            $this->titles($this->search->search($this->workspaceId, $this->user->id, 'Protokoll')),
        );
    }

    public function testANotebookCollectionSearchesOnlyThatNotebook(): void
    {
        $reisen = (int) $this->notebooks->create($this->user, ['name' => 'Reisen'])['id'];
        $arbeit = (int) $this->notebooks->create($this->user, ['name' => 'Arbeit'])['id'];
        $this->pages->create($this->user, 'note', 'Protokoll Montag', null, $reisen);
        $this->pages->create($this->user, 'note', 'Protokoll Dienstag', null, $arbeit);
        $this->pages->create($this->user, 'note', 'Protokoll ohne Buch', null);

        self::assertSame(
            ['Protokoll Montag'],
            $this->titles($this->search->search(
                $this->workspaceId,
                $this->user->id,
                'Protokoll',
                'notebook',
                $reisen,
            )),
        );
    }

    public function testTheUnassignedCollectionLeavesOutPagesInANotebook(): void
    {
        $notebook = (int) $this->notebooks->create($this->user, ['name' => 'Reisen'])['id'];
        $this->pages->create($this->user, 'note', 'Protokoll Montag', null, $notebook);
        $this->pages->create($this->user, 'note', 'Protokoll ohne Buch', null);

        self::assertSame(
            ['Protokoll ohne Buch'],
            $this->titles($this->search->search(
                $this->workspaceId,
                $this->user->id,
                'Protokoll',
                'unassigned',
            )),
        );
    }

    public function testTheFavouritesCollectionSearchesOnlyFavourites(): void
    {
        $favourite = $this->pages->create($this->user, 'note', 'Protokoll Montag', null);
        $this->pages->create($this->user, 'note', 'Protokoll Dienstag', null);
        $this->pages->update($this->user, (int) $favourite['id'], ['is_favorite' => true]);

        self::assertSame(
            ['Protokoll Montag'],
            $this->titles($this->search->search(
                $this->workspaceId,
                $this->user->id,
                'Protokoll',
                'favorites',
            )),
        );
    }

    public function testPagesOfANotebookSharedWithTheUserAreFound(): void
    {
        $owner = $this->createUser('owner@example.com', 'Owner');
        $ownerWorkspace = $this->pages->workspaceIdFor($owner);
        $notebook = (int) $this->notebooks->create($owner, ['name' => 'Baustelle'])['id'];
        $this->pages->create($owner, 'note', 'Protokoll Baustelle', null, $notebook);
        $this->pages->create($owner, 'note', 'Protokoll privat', null);

        $shares = new NotebookShareService(
            new NotebookRepository($this->pdo),
            new NotebookShareRepository($this->pdo),
            new UserRepository($this->pdo, new AdminEmails('')),
            new WorkspaceRepository($this->pdo),
        );
        $shares->share($owner, $notebook, 'a@example.com');

        self::assertSame(
            ['Protokoll Baustelle'],
            $this->titles($this->search->search($this->workspaceId, $this->user->id, 'Protokoll')),
        );
        self::assertSame(
            ['Protokoll Baustelle'],
            $this->titles($this->search->search(
                $this->workspaceId,
                $this->user->id,
                'Protokoll',
                'notebook',
                $notebook,
            )),
        );
        self::assertNotSame($ownerWorkspace, $this->workspaceId);
    }

    public function testUmlautsMatchRegardlessOfCase(): void
    {
        $this->pages->create($this->user, 'note', 'Änderung Küche', null);

        self::assertSame(
            ['Änderung Küche'],
            $this->titles($this->search->search($this->workspaceId, $this->user->id, 'änderung KÜCHE')),
        );
    }

    public function testPercentSignsAreNoWildcards(): void
    {
        $this->pages->create($this->user, 'note', 'Rabatt 10% vereinbart', null);
        $this->pages->create($this->user, 'note', 'Rabatt 100 Euro', null);

        self::assertSame(
            ['Rabatt 10% vereinbart'],
            $this->titles($this->search->search($this->workspaceId, $this->user->id, '10%')),
        );
    }

    public function testResultsArePagedWithAHasMoreFlag(): void
    {
        $search = new SearchRepository($this->pdo, 2);
        foreach (['Eins', 'Zwei', 'Drei'] as $title) {
            $this->pages->create($this->user, 'note', 'Protokoll ' . $title, null);
        }

        $first = $search->search($this->workspaceId, $this->user->id, 'Protokoll');
        $second = $search->search($this->workspaceId, $this->user->id, 'Protokoll', null, null, 2);

        self::assertCount(2, $first['pages']);
        self::assertTrue($first['has_more']);
        self::assertCount(1, $second['pages']);
        self::assertFalse($second['has_more']);
    }

    private function createUser(string $email, string $name): User
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (google_sub, email, name, created_at) VALUES (:sub, :email, :name, :now)'
        );
        $stmt->execute(['sub' => $email, 'email' => $email, 'name' => $name, 'now' => gmdate('Y-m-d\TH:i:s.v\Z')]);
        $userId = (int) $this->pdo->lastInsertId();
        (new WorkspaceRepository($this->pdo))->createForUser($userId);

        return new User($userId, $email, $name, $email, null, true, false);
    }

    /**
     * @param array{pages: array<int, array<string, mixed>>, has_more: bool} $results
     * @return array<int, string>
     */
    private function titles(array $results): array
    {
        $titles = array_map(static fn (array $page): string => (string) $page['title'], $results['pages']);
        sort($titles);

        return $titles;
    }
}
