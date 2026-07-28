<?php

declare(strict_types=1);

namespace Tests\Integration\Domain\Notes;

use App\Domain\Notes\NoteService;
use App\Domain\Notes\NoteWriteUnavailableException;
use App\Domain\Notes\ProseMirrorValidator;
use App\Domain\PageService;
use App\Domain\User;
use App\Repositories\NoteAttachmentRepository;
use App\Repositories\NoteContentRepository;
use App\Repositories\NoteVersionRepository;
use App\Repositories\PageRepository;
use App\Repositories\ShareRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\Database;
use App\Support\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

final class NoteServiceConcurrencyTest extends TestCase
{
    private ?PDO $writer = null;
    private string $databasePath = '';
    private User $user;
    private int $pageId;
    private NoteService $notes;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/notes-concurrency-' . bin2hex(random_bytes(8)) . '.sqlite';
        $writer = Database::connect($this->databasePath);
        $this->writer = $writer;
        (new Migrator($writer, dirname(__DIR__, 4) . '/database/migrations'))->migrate();
        $contender = Database::connect($this->databasePath);
        $contender->exec('PRAGMA busy_timeout = 1');

        $writerWorkspaces = new WorkspaceRepository($writer);
        $userId = $this->createUser($writer, $writerWorkspaces);
        $this->user = new User($userId, 'concurrent-user', 'concurrent@example.com', 'Concurrent User', null, true, false);

        $writerPages = new PageRepository($writer);
        $writerPageService = new PageService($writerPages, $writerWorkspaces, new ShareRepository($writer));
        $page = $writerPageService->create($this->user, 'note', 'Nebenläufigkeit', null);
        $this->pageId = (int) $page['id'];

        $contenderPages = new PageRepository($contender);
        $contenderWorkspaces = new WorkspaceRepository($contender);
        $this->notes = new NoteService(
            $contender,
            new PageService($contenderPages, $contenderWorkspaces, new ShareRepository($contender)),
            $contenderPages,
            new NoteContentRepository($contender),
            new NoteVersionRepository($contender),
            new NoteAttachmentRepository($contender),
            new ProseMirrorValidator(),
        );
    }

    protected function tearDown(): void
    {
        $this->writer = null;
        if ($this->databasePath !== '') {
            @unlink($this->databasePath);
            @unlink($this->databasePath . '-shm');
            @unlink($this->databasePath . '-wal');
        }
    }

    public function testBusyDatabaseReturnsRetryableNoteError(): void
    {
        $writer = $this->writer;
        self::assertInstanceOf(PDO::class, $writer);
        $writer->exec('BEGIN IMMEDIATE');

        try {
            $this->expectException(NoteWriteUnavailableException::class);
            $this->notes->save($this->user, $this->pageId, $this->doc('Neuer Stand'), 1);
        } finally {
            $writer->exec('ROLLBACK');
        }
    }

    /** @return array<string, mixed> */
    private function doc(string $text): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    }

    private function createUser(PDO $pdo, WorkspaceRepository $workspaces): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO users (google_sub, email, name, created_at) VALUES (:sub, :email, :name, :created_at)'
        );
        $stmt->execute([
            'sub' => 'concurrent-user',
            'email' => 'concurrent@example.com',
            'name' => 'Concurrent User',
            'created_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
        $userId = (int) $pdo->lastInsertId();
        $workspaces->createForUser($userId);

        return $userId;
    }
}
