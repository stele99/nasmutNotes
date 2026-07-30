<?php

declare(strict_types=1);

namespace Tests\Integration\Domain;

use App\Domain\Notes\NoteEncryptionException;
use App\Domain\PageService;
use App\Domain\ShareService;
use App\Domain\User;
use App\Repositories\PageRepository;
use App\Repositories\ShareRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\NotFoundException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDatabaseTrait;

final class ShareServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PDO $pdo;
    private PageService $pages;
    private ShareService $shares;
    private User $userA;
    private User $userB;

    protected function setUp(): void
    {
        $this->pdo = $this->makeDatabase();
        $workspaces = new WorkspaceRepository($this->pdo);
        $shareRepository = new ShareRepository($this->pdo);
        $this->pages = new PageService(new PageRepository($this->pdo), $workspaces, $shareRepository);
        $this->shares = new ShareService($this->pages, $shareRepository);
        $this->userA = $this->makeUser($workspaces, 'a@example.com');
        $this->userB = $this->makeUser($workspaces, 'b@example.com');
    }

    public function testRecipientGetsSharedPageAfterOpeningWriteLink(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Gemeinsame Notiz', null);
        $share = $this->shares->create($this->userA, (int) $page['id'], 'write');

        $opened = $this->shares->open($this->userB, $share['token']);
        $received = $this->pages->find($this->userB, (int) $page['id']);
        $sharedPages = $this->pages->list($this->userB, 'updated', null, false);

        self::assertSame((int) $page['id'], (int) $opened['page_id']);
        self::assertTrue($received['is_shared']);
        self::assertSame('write', $received['share_permission']);
        self::assertTrue($received['can_edit']);
        self::assertCount(1, $sharedPages);
        self::assertSame((int) $page['id'], (int) $sharedPages[0]['id']);

        $statement = $this->pdo->query('SELECT token_hash FROM share_links');
        if ($statement === false) {
            self::fail('Share-Link konnte nicht gelesen werden.');
        }
        $stored = $statement->fetch();
        self::assertIsArray($stored);
        self::assertNotSame($share['token'], $stored['token_hash']);
    }

    public function testReadShareRejectsWriteAccess(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Nur Lesen', null);
        $share = $this->shares->create($this->userA, (int) $page['id'], 'read');
        try {
            $this->shares->open($this->userB, $share['token']);
            self::fail('Öffentliche Leselinks dürfen nicht als Workspace-Zugriff angenommen werden.');
        } catch (\App\Support\ValidationException) {
        }

        $this->expectException(NotFoundException::class);
        $this->pages->find($this->userB, (int) $page['id']);
    }

    public function testWriteShareAllowsWriteAccess(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Bearbeitbar', null);
        $share = $this->shares->create($this->userA, (int) $page['id'], 'write');
        $this->shares->open($this->userB, $share['token']);

        $received = $this->pages->find($this->userB, (int) $page['id']);

        self::assertTrue($received['is_shared']);
        self::assertSame('write', $received['share_permission']);
        self::assertTrue($received['can_edit']);
    }

    public function testAcceptedWritersIncludeOwnerAndDeduplicateActiveWriteRecipients(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Kollaboration', null);
        $writeShareA = $this->shares->create($this->userA, (int) $page['id'], 'write');
        $writeShareB = $this->shares->create($this->userA, (int) $page['id'], 'write');

        $this->shares->open($this->userB, $writeShareA['token']);
        $this->shares->open($this->userB, $writeShareB['token']);

        $writers = $this->shares->listAcceptedWriters($this->userB, (int) $page['id']);

        self::assertCount(2, $writers);
        self::assertSame($this->userA->id, (int) $writers[0]['id']);
        self::assertSame(1, (int) $writers[0]['is_owner']);
        self::assertSame($this->userB->id, (int) $writers[1]['id']);

        $this->shares->revokeAll($this->userA, (int) $page['id']);

        $writers = $this->shares->listAcceptedWriters($this->userA, (int) $page['id']);
        self::assertCount(1, $writers);
        self::assertSame($this->userA->id, (int) $writers[0]['id']);
    }

    public function testCollaboratorsIncludeWriteRecipients(): void
    {
        $page = $this->pages->create($this->userA, 'task', 'Geteilte Liste', null);
        $writeShare = $this->shares->create($this->userA, (int) $page['id'], 'write');
        $this->shares->open($this->userB, $writeShare['token']);

        $collaborators = $this->shares->listCollaborators($this->userA, (int) $page['id']);
        $writers = $this->shares->listAcceptedWriters($this->userA, (int) $page['id']);

        self::assertSame(
            [$this->userA->id, $this->userB->id],
            array_map('intval', array_column($collaborators, 'id')),
        );
        self::assertSame(1, (int) $collaborators[0]['is_owner']);
        self::assertSame(0, (int) $collaborators[1]['is_owner']);
        self::assertCount(2, $writers);
    }

    public function testCollaboratorsDropRevokedShares(): void
    {
        $page = $this->pages->create($this->userA, 'task', 'Geteilte Liste', null);
        $share = $this->shares->create($this->userA, (int) $page['id'], 'write');
        $this->shares->open($this->userB, $share['token']);
        $this->shares->revokeAll($this->userA, (int) $page['id']);

        $collaborators = $this->shares->listCollaborators($this->userA, (int) $page['id']);

        self::assertCount(1, $collaborators);
        self::assertSame($this->userA->id, (int) $collaborators[0]['id']);
    }

    public function testRevokedShareDoesNotGrantAccess(): void
    {
        $page = $this->pages->create($this->userA, 'task', 'Widerrufene Liste', null);
        $share = $this->shares->create($this->userA, (int) $page['id'], 'write');
        $this->shares->open($this->userB, $share['token']);
        $this->shares->revoke($this->userA, $share['id']);

        $this->expectException(NotFoundException::class);
        $this->pages->find($this->userB, (int) $page['id']);
    }

    public function testRecipientCanLeaveSharedPage(): void
    {
        $page = $this->pages->create($this->userA, 'task', 'Verlassene Liste', null);
        $share = $this->shares->create($this->userA, (int) $page['id'], 'write');
        $this->shares->open($this->userB, $share['token']);

        $this->shares->leave($this->userB, (int) $page['id']);

        $this->expectException(NotFoundException::class);
        $this->pages->find($this->userB, (int) $page['id']);
    }

    public function testShareCannotBeManagedByAnotherUser(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Private Freigabe', null);
        $share = $this->shares->create($this->userA, (int) $page['id'], 'read');

        $this->expectException(NotFoundException::class);
        $this->shares->revoke($this->userB, $share['id']);
    }

    public function testSharedCollectionContainsOwnedAndReceivedPages(): void
    {
        $owned = $this->pages->create($this->userA, 'note', 'Von mir geteilt', null);
        $this->shares->create($this->userA, (int) $owned['id'], 'read');

        $received = $this->pages->create($this->userB, 'task', 'Mit mir geteilt', null);
        $writeShare = $this->shares->create($this->userB, (int) $received['id'], 'write');
        $this->shares->open($this->userA, $writeShare['token']);

        $shared = $this->pages->list($this->userA, 'updated', null, false, null, 'shared');

        $byId = [];
        foreach ($shared as $page) {
            $byId[(int) $page['id']] = $page;
        }
        self::assertSame([(int) $owned['id'], (int) $received['id']], array_keys($byId));
        self::assertFalse($byId[(int) $owned['id']]['is_shared']);
        self::assertTrue($byId[(int) $received['id']]['is_shared']);
    }

    public function testEncryptedNoteCanBeSharedForReadAndWriteButNotCopy(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Verschlüsselt', null);
        $this->pdo->prepare('UPDATE pages SET is_encrypted = 1 WHERE id = :id')
            ->execute(['id' => (int) $page['id']]);

        self::assertSame('read', $this->shares->create($this->userA, (int) $page['id'], 'read')['permission']);
        self::assertSame('write', $this->shares->create($this->userA, (int) $page['id'], 'write')['permission']);

        try {
            $this->shares->create($this->userA, (int) $page['id'], 'read_copy');
            self::fail('Verschlüsselte Notiz wurde zum Kopieren geteilt.');
        } catch (NoteEncryptionException $exception) {
            self::assertSame('ENCRYPTION_COPY_UNAVAILABLE', $exception->errorCode);
        }
    }

    private function makeUser(WorkspaceRepository $workspaces, string $email): User
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (google_sub, email, name, created_at)
             VALUES (:sub, :email, :name, :now)'
        );
        $stmt->execute([
            'sub' => $email,
            'email' => $email,
            'name' => $email,
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $workspaces->createForUser($id);

        return new User($id, $email, $email, $email, null, true, false);
    }
}
