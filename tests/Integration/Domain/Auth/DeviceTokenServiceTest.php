<?php

declare(strict_types=1);

namespace Tests\Integration\Domain\Auth;

use App\Domain\Auth\DeviceTokenService;
use App\Domain\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\DeviceTokenRepository;
use App\Repositories\UserRepository;
use App\Support\AdminEmails;
use App\Support\NotFoundException;
use App\Support\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDatabaseTrait;

final class DeviceTokenServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PDO $pdo;
    private DeviceTokenRepository $tokens;
    private DeviceTokenService $service;
    private User $userA;
    private User $userB;

    protected function setUp(): void
    {
        $this->pdo = $this->makeDatabase();
        $this->tokens = new DeviceTokenRepository($this->pdo);
        $users = new UserRepository($this->pdo, new AdminEmails(''));
        $this->service = new DeviceTokenService($this->tokens, $users, new AuditLogRepository($this->pdo));
        $this->userA = $this->makeUser('a@example.com');
        $this->userB = $this->makeUser('b@example.com');
    }

    public function testTokenIsStoredOnlyAsHash(): void
    {
        $result = $this->service->issue($this->userA, 'iPhone', 'ip-hash');

        $stored = $this->tokens->findActiveByHash(hash('sha256', $result['token']));

        self::assertNotNull($stored);
        self::assertNotSame($result['token'], $stored['token_hash']);
        self::assertSame($this->userA->id, (int) $stored['user_id']);
        self::assertSame('iPhone', $stored['label']);
    }

    public function testResolveUserFindsOwnerOfActiveToken(): void
    {
        $result = $this->service->issue($this->userA, 'iPhone', 'ip-hash');

        $resolved = $this->service->resolveUser($result['token']);

        self::assertNotNull($resolved);
        self::assertSame($this->userA->id, $resolved->id);
    }

    public function testResolveUserRejectsUnknownToken(): void
    {
        self::assertNull($this->service->resolveUser(bin2hex(random_bytes(32))));
    }

    public function testRevokedTokenNoLongerResolves(): void
    {
        $result = $this->service->issue($this->userA, 'iPhone', 'ip-hash');
        $this->service->revoke($this->userA, $result['id'], 'ip-hash');

        self::assertNull($this->service->resolveUser($result['token']));
    }

    public function testUserCannotRevokeForeignToken(): void
    {
        $result = $this->service->issue($this->userA, 'iPhone', 'ip-hash');

        $this->expectException(NotFoundException::class);
        $this->service->revoke($this->userB, $result['id'], 'ip-hash');
    }

    public function testEmptyLabelIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->issue($this->userA, '  ', 'ip-hash');
    }

    public function testListForOnlyShowsOwnActiveTokens(): void
    {
        $this->service->issue($this->userA, 'iPhone', 'ip-hash');
        $revoked = $this->service->issue($this->userA, 'Altes iPad', 'ip-hash');
        $this->service->issue($this->userB, 'Anderes Gerät', 'ip-hash');
        $this->service->revoke($this->userA, $revoked['id'], 'ip-hash');

        $labels = array_column($this->service->listFor($this->userA), 'label');

        self::assertSame(['iPhone'], $labels);
    }

    private function makeUser(string $email): User
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

        return new User((int) $this->pdo->lastInsertId(), $email, $email, $email, null, true, false);
    }
}
