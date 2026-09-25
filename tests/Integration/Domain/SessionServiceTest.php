<?php

declare(strict_types=1);

namespace Tests\Integration\Domain;

use App\Domain\SessionService;
use App\Repositories\SessionRepository;
use App\Repositories\UserRepository;
use App\Support\AdminEmails;
use App\Support\NotFoundException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDatabaseTrait;

final class SessionServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private const ANDROID_CHROME = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Mobile Safari/537.36';
    private const WINDOWS_FIREFOX = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0';

    private PDO $pdo;
    private SessionService $sessions;
    private int $userId;
    private int $otherUserId;

    protected function setUp(): void
    {
        $this->pdo = $this->makeDatabase();
        $this->sessions = new SessionService(
            new SessionRepository($this->pdo),
            new UserRepository($this->pdo, new AdminEmails('')),
            30,
        );
        $this->userId = $this->user('a@example.com');
        $this->otherUserId = $this->user('b@example.com');
    }

    public function testListMarksTheCurrentSessionAndDescribesDevices(): void
    {
        $current = $this->sessions->start($this->userId, self::WINDOWS_FIREFOX, null);
        $this->sessions->start($this->userId, self::ANDROID_CHROME, null);
        $this->sessions->start($this->otherUserId, self::ANDROID_CHROME, null);

        $list = $this->sessions->listForUser($this->userId, $current);

        self::assertCount(2, $list);
        $devices = array_column($list, 'current', 'device');
        self::assertTrue($devices['Firefox auf Windows']);
        self::assertFalse($devices['Chrome auf Android']);
    }

    public function testRevokeOthersKeepsTheCurrentSession(): void
    {
        $current = $this->sessions->start($this->userId, self::WINDOWS_FIREFOX, null);
        $lost = $this->sessions->start($this->userId, self::ANDROID_CHROME, null);
        $foreign = $this->sessions->start($this->otherUserId, self::ANDROID_CHROME, null);

        self::assertSame(1, $this->sessions->revokeOthers($this->userId, $current));

        self::assertNotNull($this->sessions->resolveUser($current));
        self::assertNull($this->sessions->resolveUser($lost));
        self::assertNotNull($this->sessions->resolveUser($foreign));
    }

    public function testForeignSessionCannotBeRevoked(): void
    {
        $foreign = $this->sessions->start($this->otherUserId, self::ANDROID_CHROME, null);
        $foreignId = $this->sessions->listForUser($this->otherUserId, null)[0]['id'];

        try {
            $this->sessions->revokeForUser($this->userId, $foreignId);
            self::fail('Fremde Sitzung darf nicht beendet werden.');
        } catch (NotFoundException) {
        }

        self::assertNotNull($this->sessions->resolveUser($foreign));
    }

    public function testUnknownUserAgentFallsBack(): void
    {
        self::assertSame('Unbekannter Browser', SessionService::describeUserAgent(''));
        self::assertSame('Safari auf iPhone', SessionService::describeUserAgent(
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'
        ));
    }

    private function user(string $email): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (google_sub, email, name, created_at) VALUES (:email, :email, :email, '2026-01-01T00:00:00Z')"
        );
        $stmt->execute(['email' => $email]);

        return (int) $this->pdo->lastInsertId();
    }
}
