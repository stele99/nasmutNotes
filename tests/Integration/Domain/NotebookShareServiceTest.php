<?php

declare(strict_types=1);

namespace Tests\Integration\Domain;

use App\Domain\NotebookService;
use App\Domain\NotebookShareService;
use App\Domain\PageService;
use App\Domain\User;
use App\Repositories\NotebookRepository;
use App\Repositories\NotebookShareRepository;
use App\Repositories\PageRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\AdminEmails;
use App\Support\NotFoundException;
use App\Support\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDatabaseTrait;

final class NotebookShareServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PDO $pdo;
    private WorkspaceRepository $workspaces;
    private NotebookService $notebooks;
    private NotebookShareService $shares;
    private PageService $pages;
    private User $userA;
    private User $userB;

    protected function setUp(): void
    {
        $this->pdo = $this->makeDatabase();
        $this->workspaces = new WorkspaceRepository($this->pdo);
        $notebookRepository = new NotebookRepository($this->pdo);
        $notebookShareRepository = new NotebookShareRepository($this->pdo);
        $userRepository = new UserRepository($this->pdo, new AdminEmails(''));
        $this->notebooks = new NotebookService($this->pdo, $notebookRepository, $this->workspaces, $notebookShareRepository);
        $this->shares = new NotebookShareService($notebookRepository, $notebookShareRepository, $userRepository, $this->workspaces);
        $this->pages = new PageService(
            new PageRepository($this->pdo),
            $this->workspaces,
            null,
            $this->notebooks,
            null,
            $notebookShareRepository,
        );
        $this->userA = $this->makeUser($this->workspaces, 'a@example.com');
        $this->userB = $this->makeUser($this->workspaces, 'b@example.com');
    }

    public function testOwnerSharesNotebookByEmailAndBothSeeItMarked(): void
    {
        $notebook = $this->makeNotebook('Projekte');
        $this->shares->share($this->userA, (int) $notebook['id'], 'b@example.com');

        $participants = $this->shares->listParticipants($this->userA, (int) $notebook['id']);

        self::assertCount(1, $participants);
        self::assertSame($this->userB->id, (int) $participants[0]['id']);
        self::assertSame('b@example.com', (string) $participants[0]['email']);

        $ownerList = $this->notebooks->listWithShared($this->userA);
        self::assertTrue($ownerList[0]['is_owner']);
        self::assertTrue($ownerList[0]['is_shared']);
        self::assertNull($ownerList[0]['owner_name']);

        $participantList = $this->notebooks->listWithShared($this->userB);
        self::assertCount(1, $participantList);
        self::assertSame((int) $notebook['id'], (int) $participantList[0]['id']);
        self::assertFalse($participantList[0]['is_owner']);
        self::assertTrue($participantList[0]['is_shared']);
        self::assertSame('a@example.com', (string) $participantList[0]['owner_name']);
    }

    public function testUnsharedNotebookIsNotMarked(): void
    {
        $this->makeNotebook('Privat');

        $list = $this->notebooks->listWithShared($this->userA);

        self::assertFalse($list[0]['is_shared']);
        self::assertNull($list[0]['owner_name']);
    }

    public function testShareRejectsUnregisteredEmail(): void
    {
        $notebook = $this->makeNotebook('Projekte');

        try {
            $this->shares->share($this->userA, (int) $notebook['id'], 'unbekannt@example.com');
            self::fail('Nicht registrierte E-Mail wurde akzeptiert.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('Kein registrierter Nutzer', $exception->getMessage());
        }
    }

    public function testShareRejectsInvalidAndEmptyEmail(): void
    {
        $notebook = $this->makeNotebook('Projekte');

        $this->expectException(ValidationException::class);
        $this->shares->share($this->userA, (int) $notebook['id'], 'keine-e-mail');
    }

    public function testShareRejectsOwnerAndDuplicateParticipants(): void
    {
        $notebook = $this->makeNotebook('Projekte');
        $this->shares->share($this->userA, (int) $notebook['id'], 'b@example.com');

        try {
            $this->shares->share($this->userA, (int) $notebook['id'], 'a@example.com');
            self::fail('Selbst-Freigabe wurde akzeptiert.');
        } catch (ValidationException) {
        }

        $this->expectException(ValidationException::class);
        $this->shares->share($this->userA, (int) $notebook['id'], 'b@example.com');
    }

    public function testShareRejectsInactiveUser(): void
    {
        $notebook = $this->makeNotebook('Projekte');
        $this->pdo->prepare('UPDATE users SET is_active = 0 WHERE email = :email')
            ->execute(['email' => 'b@example.com']);

        $this->expectException(ValidationException::class);
        $this->shares->share($this->userA, (int) $notebook['id'], 'b@example.com');
    }

    public function testShareCannotBeManagedByNonOwner(): void
    {
        $notebook = $this->makeNotebook('Projekte');

        try {
            $this->shares->share($this->userB, (int) $notebook['id'], 'b@example.com');
            self::fail('Fremdes Notizbuch wurde geteilt.');
        } catch (NotFoundException) {
        }

        try {
            $this->shares->listParticipants($this->userB, (int) $notebook['id']);
            self::fail('Fremdes Notizbuch wurde eingesehen.');
        } catch (NotFoundException) {
        }

        $this->expectException(NotFoundException::class);
        $this->shares->removeParticipant($this->userB, (int) $notebook['id'], $this->userA->id);
    }

    public function testRemoveParticipantRequiresExistingShareAndOwnerIsNotRemovable(): void
    {
        $notebook = $this->makeNotebook('Projekte');

        try {
            $this->shares->removeParticipant($this->userA, (int) $notebook['id'], $this->userB->id);
            self::fail('Nicht vorhandenen Teilnehmer entfernt.');
        } catch (NotFoundException) {
        }

        $this->expectException(ValidationException::class);
        $this->shares->removeParticipant($this->userA, (int) $notebook['id'], $this->userA->id);
    }

    public function testParticipantCanAccessAndEditPagesInSharedNotebook(): void
    {
        $notebook = $this->makeNotebook('Projekte');
        $page = $this->pages->create($this->userA, 'note', 'Gemeinsame Notiz', null, (int) $notebook['id']);
        $this->shares->share($this->userA, (int) $notebook['id'], 'b@example.com');

        $received = $this->pages->find($this->userB, (int) $page['id']);

        self::assertTrue($received['is_shared']);
        self::assertSame('notebook', $received['share_source']);
        self::assertSame('write', $received['share_permission']);
        self::assertTrue($received['can_edit']);

        $receivedList = $this->pages->list($this->userB, 'updated', null, false, (int) $notebook['id']);
        self::assertCount(1, $receivedList);
        self::assertSame((int) $page['id'], (int) $receivedList[0]['id']);
        self::assertSame('notebook', $receivedList[0]['share_source']);
        self::assertTrue($receivedList[0]['can_edit']);
    }

    public function testParticipantCreatesPageInsideOwnersWorkspace(): void
    {
        $notebook = $this->makeNotebook('Projekte');
        $this->shares->share($this->userA, (int) $notebook['id'], 'b@example.com');

        $created = $this->pages->create($this->userB, 'note', 'Von Teilnehmer', null, (int) $notebook['id']);

        $ownerWorkspaceId = $this->workspaces->findByUserId($this->userA->id);
        $stmt = $this->pdo->prepare('SELECT workspace_id FROM pages WHERE id = :id');
        $stmt->execute(['id' => (int) $created['id']]);
        $row = $stmt->fetch();

        self::assertIsArray($row);
        self::assertSame($ownerWorkspaceId, (int) $row['workspace_id']);

        $ownerList = $this->pages->list($this->userA, 'updated', null, false, (int) $notebook['id']);
        self::assertCount(1, $ownerList);
        self::assertSame((int) $created['id'], (int) $ownerList[0]['id']);
        self::assertFalse($ownerList[0]['is_shared']);
    }

    public function testParticipantCannotManageNotebookOrPages(): void
    {
        $notebook = $this->makeNotebook('Projekte');
        $page = $this->pages->create($this->userA, 'note', 'Geschützt', null, (int) $notebook['id']);
        $this->shares->share($this->userA, (int) $notebook['id'], 'b@example.com');

        try {
            $this->notebooks->update($this->userB, (int) $notebook['id'], ['name' => 'Übernommen']);
            self::fail('Teilnehmer hat Notizbuch umbenannt.');
        } catch (NotFoundException) {
        }

        try {
            $this->notebooks->delete($this->userB, (int) $notebook['id']);
            self::fail('Teilnehmer hat Notizbuch gelöscht.');
        } catch (NotFoundException) {
        }

        try {
            $this->pages->moveMany($this->userB, [(int) $page['id']], (int) $notebook['id']);
            self::fail('Teilnehmer hat Seiten verschoben.');
        } catch (NotFoundException) {
        }

        $this->expectException(NotFoundException::class);
        $this->pages->softDelete($this->userB, (int) $page['id']);
    }

    public function testParticipantMovesOwnPageIntoSharedNotebookWithOwnershipTransfer(): void
    {
        $notebook = $this->makeNotebook('Projekte');
        $this->shares->share($this->userA, (int) $notebook['id'], 'b@example.com');
        $own = $this->pages->create($this->userB, 'note', 'Meine Notiz', null);

        $moved = $this->pages->moveMany($this->userB, [(int) $own['id']], (int) $notebook['id']);

        self::assertSame(1, $moved);

        $ownerWorkspaceId = $this->workspaces->findByUserId($this->userA->id);
        $stmt = $this->pdo->prepare('SELECT workspace_id, notebook_id FROM pages WHERE id = :id');
        $stmt->execute(['id' => (int) $own['id']]);
        $row = $stmt->fetch();
        self::assertIsArray($row);
        self::assertSame($ownerWorkspaceId, (int) $row['workspace_id']);
        self::assertSame((int) $notebook['id'], (int) $row['notebook_id']);

        // Der frühere Eigentümer bleibt über das geteilte Notizbuch zugreifbar.
        $received = $this->pages->find($this->userB, (int) $own['id']);
        self::assertTrue($received['is_shared']);
        self::assertSame('notebook', $received['share_source']);
        self::assertTrue($received['can_edit']);

        // Der Notizbuch-Eigentümer sieht die Seite regulär in seinem Notizbuch.
        $ownerList = $this->pages->list($this->userA, 'updated', null, false, (int) $notebook['id']);
        self::assertCount(1, $ownerList);
        self::assertFalse($ownerList[0]['is_shared']);
    }

    public function testUnsharedForeignNotebookStaysInaccessible(): void
    {
        $notebook = $this->makeNotebook('Privat');
        $page = $this->pages->create($this->userA, 'note', 'Privat', null, (int) $notebook['id']);

        $this->expectException(NotFoundException::class);
        $this->pages->find($this->userB, (int) $page['id']);
    }

    public function testOwnerCanRemoveParticipantAndAccessExpires(): void
    {
        $notebook = $this->makeNotebook('Projekte');
        $page = $this->pages->create($this->userA, 'note', 'Gemeinsame Notiz', null, (int) $notebook['id']);
        $this->shares->share($this->userA, (int) $notebook['id'], 'b@example.com');

        $this->shares->removeParticipant($this->userA, (int) $notebook['id'], $this->userB->id);

        $this->expectException(NotFoundException::class);
        $this->pages->find($this->userB, (int) $page['id']);
    }

    public function testParticipantCanLeaveAndKeepsOwnerData(): void
    {
        $notebook = $this->makeNotebook('Projekte');
        $page = $this->pages->create($this->userA, 'note', 'Bleibt beim Eigentümer', null, (int) $notebook['id']);
        $this->shares->share($this->userA, (int) $notebook['id'], 'b@example.com');

        $this->shares->leave($this->userB, (int) $notebook['id']);

        $ownerPage = $this->pages->find($this->userA, (int) $page['id']);
        self::assertSame('Bleibt beim Eigentümer', (string) $ownerPage['title']);
        self::assertSame((int) $notebook['id'], (int) $ownerPage['notebook_id']);

        try {
            $this->shares->leave($this->userB, (int) $notebook['id']);
            self::fail('Verlassene Freigabe konnte erneut verlassen werden.');
        } catch (NotFoundException) {
        }

        $this->expectException(NotFoundException::class);
        $this->pages->find($this->userB, (int) $page['id']);
    }

    public function testOwnerDeleteCascadesShares(): void
    {
        $notebook = $this->makeNotebook('Projekte');
        $page = $this->pages->create($this->userA, 'note', 'Verschwindet', null, (int) $notebook['id']);
        $this->shares->share($this->userA, (int) $notebook['id'], 'b@example.com');

        $this->notebooks->delete($this->userA, (int) $notebook['id']);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM notebook_shares');
        $countStmt->execute();
        self::assertSame(0, (int) $countStmt->fetchColumn());

        $this->expectException(NotFoundException::class);
        $this->pages->find($this->userB, (int) $page['id']);
    }

    /** @return array<string, mixed> */
    private function makeNotebook(string $name): array
    {
        return $this->notebooks->create($this->userA, ['name' => $name]);
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
