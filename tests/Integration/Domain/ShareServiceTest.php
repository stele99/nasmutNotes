<?php

declare(strict_types=1);

namespace Tests\Integration\Domain;

use App\Domain\PageService;
use App\Domain\ShareService;
use App\Domain\User;
use App\Repositories\PageRepository;
use App\Repositories\ShareRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\ForbiddenException;
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

    public function testRecipientGetsSharedPageAfterOpeningLink(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Gemeinsame Notiz', null);
        $share = $this->shares->create($this->userA, (int) $page['id'], 'read');

        $opened = $this->shares->open($this->userB, $share['token']);
        $received = $this->pages->find($this->userB, (int) $page['id']);
        $sharedPages = $this->pages->list($this->userB, 'updated', null, false);

        self::assertSame((int) $page['id'], (int) $opened['page_id']);
        self::assertTrue($received['is_shared']);
        self::assertSame('read', $received['share_permission']);
        self::assertFalse($received['can_edit']);
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
        $this->shares->open($this->userB, $share['token']);

        $this->expectException(ForbiddenException::class);
        $this->pages->assertCanWrite($this->userB, (int) $page['id']);
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
        $readShare = $this->shares->create($this->userA, (int) $page['id'], 'read');
        $writeShareA = $this->shares->create($this->userA, (int) $page['id'], 'write');
        $writeShareB = $this->shares->create($this->userA, (int) $page['id'], 'write');

        $this->shares->open($this->userB, $readShare['token']);
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

    public function testCollaboratorsIncludeReadOnlyRecipients(): void
    {
        $page = $this->pages->create($this->userA, 'task', 'Geteilte Liste', null);
        $readShare = $this->shares->create($this->userA, (int) $page['id'], 'read');
        $this->shares->open($this->userB, $readShare['token']);

        $collaborators = $this->shares->listCollaborators($this->userA, (int) $page['id']);
        $writers = $this->shares->listAcceptedWriters($this->userA, (int) $page['id']);

        // Nur-Lesende gehören zur Auswahlliste für Verantwortliche, zählen aber
        // nicht als Schreibende.
        self::assertSame(
            [$this->userA->id, $this->userB->id],
            array_map('intval', array_column($collaborators, 'id')),
        );
        self::assertSame(1, (int) $collaborators[0]['is_owner']);
        self::assertSame(0, (int) $collaborators[1]['is_owner']);
        self::assertCount(1, $writers);
    }

    public function testCollaboratorsDropRevokedShares(): void
    {
        $page = $this->pages->create($this->userA, 'task', 'Geteilte Liste', null);
        $share = $this->shares->create($this->userA, (int) $page['id'], 'read');
        $this->shares->open($this->userB, $share['token']);
        $this->shares->revokeAll($this->userA, (int) $page['id']);

        $collaborators = $this->shares->listCollaborators($this->userA, (int) $page['id']);

        self::assertCount(1, $collaborators);
        self::assertSame($this->userA->id, (int) $collaborators[0]['id']);
    }

    public function testRevokedShareDoesNotGrantAccess(): void
    {
        $page = $this->pages->create($this->userA, 'task', 'Widerrufene Liste', null);
        $share = $this->shares->create($this->userA, (int) $page['id'], 'read');
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
