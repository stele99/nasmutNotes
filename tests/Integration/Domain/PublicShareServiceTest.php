<?php

declare(strict_types=1);

namespace Tests\Integration\Domain;

use App\Domain\Notes\ProseMirrorHtmlRenderer;
use App\Domain\Notes\ProseMirrorValidator;
use App\Domain\PageService;
use App\Domain\PublicShareService;
use App\Domain\ShareService;
use App\Domain\User;
use App\Repositories\CategoryRepository;
use App\Repositories\NoteAttachmentRepository;
use App\Repositories\NoteContentRepository;
use App\Repositories\PageAttachmentRepository;
use App\Repositories\PageRepository;
use App\Repositories\ShareRepository;
use App\Repositories\TaskRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\NotFoundException;
use App\Support\UploadStorage;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDatabaseTrait;

final class PublicShareServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PDO $pdo;
    private PageService $pages;
    private ShareService $shares;
    private PublicShareService $publicShares;
    private User $owner;

    protected function setUp(): void
    {
        $this->pdo = $this->makeDatabase();
        $workspaces = new WorkspaceRepository($this->pdo);
        $pageRepository = new PageRepository($this->pdo);
        $shareRepository = new ShareRepository($this->pdo);
        $this->pages = new PageService($pageRepository, $workspaces, $shareRepository);
        $this->shares = new ShareService($this->pages, $shareRepository);
        $this->publicShares = new PublicShareService(
            $shareRepository,
            $pageRepository,
            new NoteContentRepository($this->pdo),
            new CategoryRepository($this->pdo),
            new TaskRepository($this->pdo),
            new NoteAttachmentRepository($this->pdo),
            new PageAttachmentRepository($this->pdo),
            new UploadStorage(sys_get_temp_dir(), 'public-share-test-missing'),
            new ProseMirrorValidator(),
            new ProseMirrorHtmlRenderer(),
        );
        $this->owner = $this->makeUser($workspaces, 'owner@example.com');
    }

    public function testAnonymousReadRendersEscapedCurrentNoteWithoutAcceptingAccess(): void
    {
        $page = $this->pages->create($this->owner, 'note', 'Öffentlich', null);
        $document = json_encode([
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '<sicher>']]]],
        ], JSON_THROW_ON_ERROR);
        $this->pdo->prepare('UPDATE note_contents SET content = :content WHERE page_id = :page')
            ->execute(['content' => $document, 'page' => (int) $page['id']]);
        $share = $this->shares->create($this->owner, (int) $page['id'], 'read');

        $view = $this->publicShares->view($share['token']);

        self::assertSame('read', $view['share']['mode']);
        self::assertStringContainsString('&lt;sicher&gt;', (string) $view['note_html']);
        $statement = $this->pdo->query('SELECT COUNT(*) FROM shared_page_access');
        self::assertNotFalse($statement);
        self::assertSame(0, (int) $statement->fetchColumn());
    }

    public function testReadCopyModeResolvesForPublicView(): void
    {
        $page = $this->pages->create($this->owner, 'task', 'Vorlage', null);
        $share = $this->shares->create($this->owner, (int) $page['id'], 'read_copy');

        self::assertSame('read_copy', $this->publicShares->resolve($share['token'])['mode']);
    }

    public function testWriteShareCannotBeReadThroughPublicContentService(): void
    {
        $page = $this->pages->create($this->owner, 'note', 'Privat bis Login', null);
        $share = $this->shares->create($this->owner, (int) $page['id'], 'write');

        $this->expectException(NotFoundException::class);
        $this->publicShares->view($share['token']);
    }

    public function testInvalidTokenIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->publicShares->resolve('invalid');
    }

    private function makeUser(WorkspaceRepository $workspaces, string $email): User
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (google_sub, email, name, created_at) VALUES (:sub, :email, :name, :now)'
        );
        $stmt->execute(['sub' => $email, 'email' => $email, 'name' => $email, 'now' => gmdate('Y-m-d\TH:i:s.v\Z')]);
        $id = (int) $this->pdo->lastInsertId();
        $workspaces->createForUser($id);

        return new User($id, $email, $email, $email, null, true, false);
    }
}
