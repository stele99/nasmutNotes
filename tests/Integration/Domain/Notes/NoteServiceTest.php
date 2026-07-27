<?php

declare(strict_types=1);

namespace Tests\Integration\Domain\Notes;

use App\Domain\Notes\NoteContentException;
use App\Domain\Notes\NoteService;
use App\Domain\Notes\ProseMirrorValidator;
use App\Domain\Notes\VersionConflictException;
use App\Domain\PageService;
use App\Domain\ShareService;
use App\Domain\User;
use App\Repositories\NoteAttachmentRepository;
use App\Repositories\NoteContentRepository;
use App\Repositories\NoteVersionRepository;
use App\Repositories\PageRepository;
use App\Repositories\ShareRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\ForbiddenException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDatabaseTrait;

final class NoteServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PDO $pdo;
    private NoteService $notes;
    private PageService $pages;
    private ShareService $shares;
    private User $user;
    private User $otherUser;
    private int $pageId;

    protected function setUp(): void
    {
        $this->pdo = $this->makeDatabase();
        $workspaces = new WorkspaceRepository($this->pdo);
        $pageRepository = new PageRepository($this->pdo);
        $shareRepository = new ShareRepository($this->pdo);
        $this->pages = new PageService($pageRepository, $workspaces, $shareRepository);
        $this->shares = new ShareService($this->pages, $shareRepository);
        $this->notes = new NoteService(
            $this->pdo,
            $this->pages,
            $pageRepository,
            new NoteContentRepository($this->pdo),
            new NoteVersionRepository($this->pdo),
            new NoteAttachmentRepository($this->pdo),
            new ProseMirrorValidator(),
        );

        $this->user = $this->makeUser($workspaces, 'a@example.com');
        $this->otherUser = $this->makeUser($workspaces, 'b@example.com');
        $page = $this->pages->create($this->user, 'note', 'Notiz', null);
        $this->pageId = (int) $page['id'];
    }

    private function makeUser(WorkspaceRepository $workspaces, string $email): User
    {
        $stmt = $this->pdo->prepare('INSERT INTO users (google_sub, email, name, created_at) VALUES (:sub, :email, :name, :now)');
        $stmt->execute(['sub' => $email, 'email' => $email, 'name' => $email, 'now' => gmdate('Y-m-d\TH:i:s.v\Z')]);
        $id = (int) $this->pdo->lastInsertId();
        $workspaces->createForUser($id);

        return new User($id, $email, $email, $email, null, true, false);
    }

    /** @return array<string, mixed> */
    private function doc(string $text): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    }

    private function setContentUpdatedAt(string $timestamp): void
    {
        $stmt = $this->pdo->prepare('UPDATE note_contents SET updated_at = :updated_at WHERE page_id = :page_id');
        $stmt->execute(['updated_at' => $timestamp, 'page_id' => $this->pageId]);
    }

    private function versionCount(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM note_versions WHERE page_id = :page_id');
        $stmt->execute(['page_id' => $this->pageId]);

        return (int) $stmt->fetchColumn();
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
            self::assertNotNull($e->currentUpdatedAt);
            self::assertSame('a@example.com', $e->currentEditorName);
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

    public function testRapidSavesBySameUserDoNotCreateVersions(): void
    {
        $this->notes->save($this->user, $this->pageId, $this->doc('Stand A'), 1);
        $this->notes->save($this->user, $this->pageId, $this->doc('Stand B'), 2);
        $this->notes->save($this->user, $this->pageId, $this->doc('Stand C'), 3);

        self::assertSame(0, $this->versionCount());
    }

    public function testIdleGapCreatesSnapshotOfPreviousContent(): void
    {
        $this->notes->save($this->user, $this->pageId, $this->doc('Vor der Pause'), 1);
        $this->setContentUpdatedAt(gmdate('Y-m-d\TH:i:s.v\Z', time() - 31 * 60));

        $this->notes->save($this->user, $this->pageId, $this->doc('Nach der Pause'), 2);

        $versions = $this->notes->listVersions($this->user, $this->pageId);
        self::assertCount(1, $versions['versions']);
        self::assertTrue($versions['can_restore']);
        self::assertStringContainsString('Vor der Pause', $versions['versions'][0]['preview']);
        self::assertSame('Nach der Pause', $this->notes->get($this->user, $this->pageId)['content']['content'][0]['content'][0]['text']);
    }

    public function testUserChangeCreatesSnapshot(): void
    {
        $this->notes->save($this->user, $this->pageId, $this->doc('Von A'), 1);

        $share = $this->shares->create($this->user, $this->pageId, 'write');
        $this->shares->open($this->otherUser, $share['token']);
        $this->notes->save($this->otherUser, $this->pageId, $this->doc('Von B'), 2);

        $versions = $this->notes->listVersions($this->user, $this->pageId);
        self::assertCount(1, $versions['versions']);
        self::assertStringContainsString('Von A', $versions['versions'][0]['preview']);
        self::assertSame('a@example.com', $versions['versions'][0]['created_by_name']);
    }

    public function testForceSnapshotCreatesVersionEvenWithinIdleWindow(): void
    {
        $this->notes->save($this->user, $this->pageId, $this->doc('Serverstand'), 1);
        $this->notes->save($this->user, $this->pageId, $this->doc('Erzwungener Snapshot'), 2, true);

        $versions = $this->notes->listVersions($this->user, $this->pageId);
        self::assertCount(1, $versions['versions']);
        self::assertStringContainsString('Serverstand', $versions['versions'][0]['preview']);
    }

    public function testRestoreReplacesContentAndSnapshotsCurrent(): void
    {
        $this->notes->save($this->user, $this->pageId, $this->doc('Alt'), 1);
        $this->setContentUpdatedAt(gmdate('Y-m-d\TH:i:s.v\Z', time() - 31 * 60));
        $this->notes->save($this->user, $this->pageId, $this->doc('Aktuell'), 2);

        $versions = $this->notes->listVersions($this->user, $this->pageId);
        $versionId = $versions['versions'][0]['id'];

        $restored = $this->notes->restoreVersion($this->user, $this->pageId, $versionId);

        self::assertSame('Alt', $restored['content']['content'][0]['content'][0]['text']);
        self::assertSame(4, $restored['version']);

        $after = $this->notes->listVersions($this->user, $this->pageId);
        self::assertCount(2, $after['versions']);
        self::assertStringContainsString('Aktuell', $after['versions'][0]['preview']);
    }

    public function testSharedWriterCannotRestore(): void
    {
        $this->notes->save($this->user, $this->pageId, $this->doc('Owner Stand'), 1);
        $this->setContentUpdatedAt(gmdate('Y-m-d\TH:i:s.v\Z', time() - 31 * 60));
        $this->notes->save($this->user, $this->pageId, $this->doc('Später'), 2);

        $share = $this->shares->create($this->user, $this->pageId, 'write');
        $this->shares->open($this->otherUser, $share['token']);

        $versions = $this->notes->listVersions($this->otherUser, $this->pageId);
        self::assertFalse($versions['can_restore']);

        $this->expectException(ForbiddenException::class);
        $this->notes->restoreVersion($this->otherUser, $this->pageId, $versions['versions'][0]['id']);
    }

    public function testPrunesToTwentyVersions(): void
    {
        $this->notes->save($this->user, $this->pageId, $this->doc('Start'), 1);
        $version = 2;

        for ($i = 0; $i < 25; ++$i) {
            $this->setContentUpdatedAt(gmdate('Y-m-d\TH:i:s.v\Z', time() - 31 * 60));
            $this->notes->save($this->user, $this->pageId, $this->doc("Stand {$i}"), $version);
            ++$version;
        }

        self::assertSame(20, $this->versionCount());
    }
}
