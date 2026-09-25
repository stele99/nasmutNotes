<?php

declare(strict_types=1);

namespace Tests\Integration\Controllers;

use App\Controllers\HealthController;
use App\Domain\Backup\BackupLayout;
use App\Repositories\SettingsRepository;
use App\Support\UploadStorage;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\InMemoryDatabaseTrait;

/**
 * Ausgefallene Cronjobs (Sicherung, Wartung) sollen über /health auffallen,
 * ohne die Anwendung als gestört zu melden.
 */
final class HealthControllerTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/health-test-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/uploads', 0770, true);
        mkdir($this->root . '/backups/snapshots', 0770, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->root . '/backups/snapshots/*') ?: []);
        rmdir($this->root . '/backups/snapshots');
        rmdir($this->root . '/backups');
        rmdir($this->root . '/uploads');
        rmdir($this->root);
    }

    public function testMissingCronRunsAreReportedAsWarnings(): void
    {
        $body = $this->health($this->makeDatabase());

        self::assertSame('ok', $body['status']);
        self::assertNull($body['last_backup_age_hours']);
        self::assertSame(['backup_stale', 'trash_purge_stale'], $body['warnings']);
    }

    public function testRecentRunsClearTheWarnings(): void
    {
        $pdo = $this->makeDatabase();
        touch($this->root . '/backups/snapshots/' . gmdate('Y-m-d-His', time() - 3600) . '.json');
        (new SettingsRepository($pdo))->set(HealthController::TRASH_PURGE_KEY, gmdate('Y-m-d\TH:i:s\Z'));

        $body = $this->health($pdo);

        self::assertSame(1, $body['last_backup_age_hours']);
        self::assertSame(0, $body['last_trash_purge_age_hours']);
        self::assertSame([], $body['warnings']);
    }

    /** @return array<string, mixed> */
    private function health(\PDO $pdo): array
    {
        $controller = new HealthController(
            $pdo,
            new UploadStorage($this->root, $this->root . '/uploads'),
            new BackupLayout($this->root . '/backups'),
        );
        $response = $controller(
            (new ServerRequestFactory())->createServerRequest('GET', '/health'),
            (new ResponseFactory())->createResponse(),
        );
        self::assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
