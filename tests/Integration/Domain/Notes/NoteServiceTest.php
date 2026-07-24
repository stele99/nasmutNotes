<?php

declare(strict_types=1);

namespace Tests\Integration\Domain\Notes;

use App\Domain\Notes\NoteContentException;
use App\Domain\Notes\NoteService;
use App\Domain\Notes\ProseMirrorValidator;
use App\Domain\Notes\VersionConflictException;
use App\Domain\PageService;
use App\Domain\User;
use App\Repositories\NoteAttachmentRepository;
use App\Repositories\NoteContentRepository;
use App\Repositories\PageRepository;
use App\Repositories\WorkspaceRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDatabaseTrait;

final class NoteServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private NoteService $notes;
    private User $user;
    private int $pageId;

    protected function setUp(): void
    {
        $pdo = $this->makeDatabase();
        $workspaces = new WorkspaceRepository($pdo);
        $pages = new PageService(new PageRepository($pdo), $workspaces);
        $this->notes = new NoteService(
            $pages,
            new NoteContentRepository($pdo),
            new NoteAttachmentRepository($pdo),
            new ProseMirrorValidator(),
        );

        $this->user = $this->makeUser($pdo, $workspaces, 'a@example.com');
        $page = $pages->create($this->user, 'note', 'Notiz', null);
        $this->pageId = (int) $page['id'];
    }

    private function makeUser(PDO $pdo, WorkspaceRepository $workspaces, string $email): User
    {
        $stmt = $pdo->prepare('INSERT INTO users (google_sub, email, name, created_at) VALUES (:sub, :email, :name, :now)');
        $stmt->execute(['sub' => $email, 'email' => $email, 'name' => $email, 'now' => gmdate('Y-m-d\TH:i:s.v\Z')]);
        $id = (int) $pdo->lastInsertId();
        $workspaces->createForUser($id);

        return new User($id, $email, $email, $email, null, true, false);
    }

    /** @return array<string, mixed> */
    private function doc(string $text): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    }

    public function testInitialContentIsEmptyDocAtVersionOne(): void
    {
        $result = $this->notes->get($this->user, $this->pageId);

        self::assertSame(1, $result['version']);
        self::assertSame('doc', $result['content']['type']);
    }

    public function testSaveIncrementsVersionAndPersistsContent(): void
    {
        $result = $this->notes->save($this->user, $this->pageId, $this->doc('Hallo Welt'), 1);

        self::assertSame(2, $result['version']);

        $reloaded = $this->notes->get($this->user, $this->pageId);
        self::assertSame(2, $reloaded['version']);
    }

    public function testStaleVersionThrowsConflictWithCurrentState(): void
    {
        $this->notes->save($this->user, $this->pageId, $this->doc('Erste Änderung'), 1);

        try {
            $this->notes->save($this->user, $this->pageId, $this->doc('Konkurrierende Änderung'), 1);
            self::fail('Erwartete VersionConflictException wurde nicht geworfen.');
        } catch (VersionConflictException $e) {
            self::assertSame(2, $e->currentVersion);
        }
    }

    public function testRejectsAttachmentThatDoesNotBelongToPage(): void
    {
        $this->expectException(NoteContentException::class);

        $this->notes->save($this->user, $this->pageId, [
            'type' => 'doc',
            'content' => [[
                'type' => 'image',
                'attrs' => [
                    'src' => '/api/attachments/' . str_repeat('c', 64),
                    'alt' => 'Screenshot',
                    'title' => null,
                    'width' => 1,
                    'height' => 1,
                ],
            ]],
        ], 1);
    }
}
