<?php

declare(strict_types=1);

namespace Tests\Integration\Domain;

use App\Domain\InviteService;
use App\Domain\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\InviteRepository;
use App\Support\ForbiddenException;
use App\Support\NotFoundException;
use App\Support\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDatabaseTrait;

final class InviteServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PDO $pdo;
    private InviteRepository $invites;
    private InviteService $service;
    private User $userA;
    private User $userB;
    private User $admin;

    protected function setUp(): void
    {
        $this->pdo = $this->makeDatabase();
        $this->invites = new InviteRepository($this->pdo);
        $this->service = new InviteService($this->invites, new AuditLogRepository($this->pdo));
        $this->userA = $this->makeUser('a@example.com', false);
        $this->userB = $this->makeUser('b@example.com', false);
        $this->admin = $this->makeUser('admin@example.com', true);
    }

    public function testAnyUserCanCreateAnInvite(): void
    {
        $invite = $this->service->create($this->userA, ['note' => 'Für Anna'], 'ip-hash');

        $stored = $this->invites->findByTokenHash(hash('sha256', $invite['token']));

        self::assertNotNull($stored);
        self::assertSame($this->userA->id, (int) $stored['created_by']);
        self::assertSame('Für Anna', $stored['note']);
        self::assertStringEndsWith('/invite/' . $invite['token'], $invite['invite_url']);
    }

    public function testTokenIsStoredOnlyAsHash(): void
    {
        $invite = $this->service->create($this->userA, [], 'ip-hash');

        $statement = $this->pdo->query('SELECT token_hash FROM invites');
        if ($statement === false) {
            self::fail('Einladung konnte nicht gelesen werden.');
        }
        $row = $statement->fetch();

        self::assertIsArray($row);
        self::assertNotSame($invite['token'], $row['token_hash']);
        self::assertSame(hash('sha256', $invite['token']), $row['token_hash']);
    }

    public function testUserSeesOnlyOwnInvites(): void
    {
        $this->service->create($this->userA, ['note' => 'A1'], 'ip-hash');
        $this->service->create($this->userA, ['note' => 'A2'], 'ip-hash');
        $this->service->create($this->userB, ['note' => 'B1'], 'ip-hash');

        $ownNotes = array_column($this->service->listFor($this->userA, false), 'note');

        self::assertCount(2, $ownNotes);
        self::assertContains('A1', $ownNotes);
        self::assertContains('A2', $ownNotes);
        self::assertNotContains('B1', $ownNotes);
    }

    public function testAdminListingContainsAllInvites(): void
    {
        $this->service->create($this->userA, ['note' => 'A1'], 'ip-hash');
        $this->service->create($this->userB, ['note' => 'B1'], 'ip-hash');

        self::assertCount(2, $this->service->listFor($this->admin, true));
    }

    public function testUserCannotRevokeForeignInvite(): void
    {
        $invite = $this->service->create($this->userA, [], 'ip-hash');

        $this->expectException(ForbiddenException::class);
        $this->service->revoke($this->userB, $invite['id'], 'ip-hash', false);
    }

    public function testUserCanRevokeOwnInvite(): void
    {
        $invite = $this->service->create($this->userA, [], 'ip-hash');

        $this->service->revoke($this->userA, $invite['id'], 'ip-hash', false);

        $stored = $this->invites->findById($invite['id']);
        self::assertNotNull($stored);
        self::assertSame('revoked', InviteRepository::status($stored));
    }

    public function testAdminCanRevokeForeignInvite(): void
    {
        $invite = $this->service->create($this->userA, [], 'ip-hash');

        $this->service->revoke($this->admin, $invite['id'], 'ip-hash', true);

        $stored = $this->invites->findById($invite['id']);
        self::assertNotNull($stored);
        self::assertSame('revoked', InviteRepository::status($stored));
    }

    public function testRevokingUnknownInviteFails(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service->revoke($this->userA, 4242, 'ip-hash', true);
    }

    public function testInvalidEmailIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->create($this->userA, ['email' => 'kein-mail'], 'ip-hash');
    }

    public function testUsesAndLifetimeAreCapped(): void
    {
        $invite = $this->service->create(
            $this->userA,
            ['max_uses' => 9999, 'ttl_days' => 9999],
            'ip-hash',
        );

        $stored = $this->invites->findById($invite['id']);
        self::assertNotNull($stored);
        self::assertSame(50, (int) $stored['max_uses']);
        self::assertLessThan(
            gmdate('Y-m-d\TH:i:s.v\Z', time() + (366 * 86400)),
            (string) $stored['expires_at'],
        );
    }

    private function makeUser(string $email, bool $isAdmin): User
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

        return new User((int) $this->pdo->lastInsertId(), $email, $email, $email, null, true, $isAdmin);
    }
}
