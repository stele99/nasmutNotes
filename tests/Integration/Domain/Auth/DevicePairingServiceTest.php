<?php

declare(strict_types=1);

namespace Tests\Integration\Domain\Auth;

use App\Domain\Auth\DevicePairingService;
use App\Domain\Auth\DeviceTokenService;
use App\Domain\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\DevicePairRequestRepository;
use App\Repositories\DeviceTokenRepository;
use App\Repositories\UserRepository;
use App\Support\AdminEmails;
use App\Support\NotFoundException;
use App\Support\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDatabaseTrait;

final class DevicePairingServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PDO $pdo;
    private DevicePairingService $service;
    private DeviceTokenRepository $tokens;
    private User $user;

    protected function setUp(): void
    {
        $this->pdo = $this->makeDatabase();
        $this->tokens = new DeviceTokenRepository($this->pdo);
        $users = new UserRepository($this->pdo, new AdminEmails(''));
        $tokenService = new DeviceTokenService($this->tokens, $users, new AuditLogRepository($this->pdo));
        $this->service = new DevicePairingService(
            new DevicePairRequestRepository($this->pdo),
            $tokenService,
            $users,
            new AuditLogRepository($this->pdo),
        );
        $this->user = $this->makeUser('a@example.com');
    }

    public function testStartReturnsDisplayCodeAndSecretDeviceCode(): void
    {
        $result = $this->startPairing();

        self::assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $result['user_code']);
        self::assertNotSame($result['user_code'], $result['device_code']);
        self::assertSame(600, $result['expires_in']);
    }

    public function testStartInvalidatesPreviousSessionOfSameClient(): void
    {
        $first = $this->startPairing();
        $second = $this->startPairing();

        $pending = $this->service->poll($first['device_code']);
        self::assertSame('expired', $pending['status']);

        $this->service->approve($this->user, $second['user_code'], 'iphash');
        $approved = $this->service->poll($second['device_code']);
        self::assertSame('approved', $approved['status']);
    }

    public function testPairingFlowIssuesTokenExactlyOnce(): void
    {
        $pair = $this->startPairing();

        self::assertSame('pending', $this->service->poll($pair['device_code'])['status']);

        $this->service->approve($this->user, $pair['user_code'], 'iphash');

        $first = $this->service->poll($pair['device_code']);
        self::assertSame('approved', $first['status']);
        $rawToken = (string) ($first['token'] ?? '');
        self::assertNotSame('', $rawToken);
        self::assertSame('a@example.com', $first['user']['email'] ?? '');

        $second = $this->service->poll($pair['device_code']);
        self::assertSame('expired', $second['status']);

        // Der Token funktioniert und ist der gepaarte Client des Nutzers.
        $resolved = $this->tokens->findActiveByHash(hash('sha256', $rawToken));
        self::assertNotNull($resolved);
        self::assertSame('desktop', $resolved['source']);
        self::assertSame('client-uuid-1', $resolved['client_id']);
        self::assertSame('Arbeits-PC', $resolved['label']);
    }

    public function testRePairingRotatesTheTokenOfSameClient(): void
    {
        $first = $this->startPairing();
        $this->service->approve($this->user, $first['user_code'], 'iphash');
        $firstToken = (string) ($this->service->poll($first['device_code'])['token'] ?? '');

        $second = $this->startPairing();
        $this->service->approve($this->user, $second['user_code'], 'iphash');
        $secondResult = $this->service->poll($second['device_code']);

        self::assertSame('approved', $secondResult['status']);
        $secondToken = (string) ($secondResult['token'] ?? '');
        self::assertNotSame('', $secondToken);

        $active = $this->tokens->allActiveForUser($this->user->id);
        self::assertCount(1, $active);
        self::assertSame('desktop', $active[0]['source']);

        // Der alte Token ist ungültig, der neue funktioniert.
        self::assertNull($this->tokens->findActiveByHash(hash('sha256', $firstToken)));
        self::assertNotNull($this->tokens->findActiveByHash(hash('sha256', $secondToken)));
    }

    public function testUnknownUserCodeCannotBeApproved(): void
    {
        $this->expectException(NotFoundException::class);
        $this->service->approve($this->user, 'ZZZZ-ZZZZ', 'iphash');
    }

    public function testDoubleApprovalIsRejected(): void
    {
        $pair = $this->startPairing();
        $this->service->approve($this->user, $pair['user_code'], 'iphash');

        $this->expectException(ValidationException::class);
        $this->service->approve($this->user, $pair['user_code'], 'iphash');
    }

    public function testDescribeByUserCodeShowsClientWithoutSecrets(): void
    {
        $pair = $this->startPairing();

        $description = $this->service->describeByUserCode($pair['user_code']);
        self::assertNotNull($description);
        self::assertSame('Arbeits-PC', $description['label']);
        self::assertSame('Windows', $description['platform']);

        self::assertNull($this->service->describeByUserCode('zzzz-zzzz'));
    }

    /** @return array{user_code: string, device_code: string, expires_in: int} */
    private function startPairing(): array
    {
        return $this->service->start('Arbeits-PC', 'client-uuid-1', 'Windows');
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
