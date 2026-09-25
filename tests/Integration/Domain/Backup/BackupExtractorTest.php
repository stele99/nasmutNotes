<?php

declare(strict_types=1);

namespace Tests\Integration\Domain\Backup;

use App\Domain\Backup\BackupExtractor;
use App\Domain\Backup\BackupLayout;
use App\Domain\Backup\BackupService;
use App\Repositories\AuditLogRepository;
use App\Support\Database;
use App\Support\Migrator;
use App\Support\NotFoundException;
use FilesystemIterator;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

/**
 * Einzelnen Nutzer aus einer Sicherung zurückholen, ohne die Datenbank zu
 * ersetzen (backup:extract).
 */
final class BackupExtractorTest extends TestCase
{
    private string $root;
    private string $uploads;
    private string $backups;
    private string $databasePath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/extract-test-' . bin2hex(random_bytes(8));
        $this->uploads = $this->root . '/uploads';
        $this->backups = $this->root . '/backups';
        $this->databasePath = $this->root . '/data/app.sqlite';
        mkdir($this->uploads, 0770, true);
        mkdir(dirname($this->databasePath), 0770, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testPurgedPageOfOneUserComesBackAsImportArchive(): void
    {
        $pdo = $this->database();
        $userId = $this->user($pdo, 'anna@example.com');
        $otherId = $this->user($pdo, 'bert@example.com');
        $pageId = $this->notePage($pdo, $userId, 'Aufmaß Küche', 'Wand links 3,20 m');
        $this->notePage($pdo, $otherId, 'Fremde Notiz', 'geheim');

        $snapshot = $this->service($pdo)->create();

        // Nach der Sicherung endgültig gelöscht.
        $pdo->exec("DELETE FROM pages WHERE id = {$pageId}");
        unset($pdo);

        $target = $this->root . '/out/anna.zip';
        $result = $this->extractor()->extract($snapshot['id'], 'anna@example.com', $target);

        self::assertSame(1, $result['pages']);
        $names = $this->zipEntries($target);
        self::assertCount(1, array_filter($names, static fn (string $name): bool => str_ends_with($name, 'Aufmaß Küche.md')));
        self::assertSame([], array_filter($names, static fn (string $name): bool => str_contains($name, 'Fremde')));

        // Die laufende Datenbank bleibt unverändert.
        $live = Database::connect($this->databasePath);
        $count = $live->query('SELECT COUNT(*) FROM pages');
        self::assertNotFalse($count);
        self::assertSame(1, (int) $count->fetchColumn());
    }

    public function testTrashedPagesAreOnlyIncludedOnRequest(): void
    {
        $pdo = $this->database();
        $userId = $this->user($pdo, 'anna@example.com');
        $this->notePage($pdo, $userId, 'Aktiv', 'a');
        $trashed = $this->notePage($pdo, $userId, 'Im Papierkorb', 'b');
        $pdo->exec("UPDATE pages SET deleted_at = '2026-09-01T00:00:00.000Z' WHERE id = {$trashed}");
        $snapshot = $this->service($pdo)->create();
        unset($pdo);

        $without = $this->extractor()->extract($snapshot['id'], 'anna@example.com', $this->root . '/a.zip');
        $with = $this->extractor()->extract($snapshot['id'], 'anna@example.com', $this->root . '/b.zip', true);

        self::assertSame(1, $without['pages']);
        self::assertSame(2, $with['pages']);
    }

    public function testUnknownAccountIsReported(): void
    {
        $pdo = $this->database();
        $this->user($pdo, 'anna@example.com');
        $snapshot = $this->service($pdo)->create();
        unset($pdo);

        $this->expectException(NotFoundException::class);
        $this->extractor()->extract($snapshot['id'], 'niemand@example.com', $this->root . '/x.zip');
    }

    private function database(): PDO
    {
        $pdo = Database::connect($this->databasePath);
        new Migrator($pdo, dirname(__DIR__, 4) . '/database/migrations')->migrate();

        return $pdo;
    }

    private function user(PDO $pdo, string $email): int
    {
        $stmt = $pdo->prepare(
            "INSERT INTO users (google_sub, email, name, created_at) VALUES (:email, :email, :email, '2026-01-01T00:00:00Z')"
        );
        $stmt->execute(['email' => $email]);
        $userId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO workspaces (user_id) VALUES ({$userId})");

        return $userId;
    }

    private function notePage(PDO $pdo, int $userId, string $title, string $text): int
    {
        $workspace = $pdo->query("SELECT id FROM workspaces WHERE user_id = {$userId}");
        self::assertNotFalse($workspace);
        $stmt = $pdo->prepare("INSERT INTO pages (workspace_id, type, title) VALUES (:workspace, 'note', :title)");
        $stmt->execute(['workspace' => (int) $workspace->fetchColumn(), 'title' => $title]);
        $pageId = (int) $pdo->lastInsertId();

        $content = json_encode([
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]],
        ], JSON_THROW_ON_ERROR);
        $stmt = $pdo->prepare('INSERT INTO note_contents (page_id, content, content_text) VALUES (:page, :content, :text)');
        $stmt->execute(['page' => $pageId, 'content' => $content, 'text' => $text]);

        return $pageId;
    }

    private function service(PDO $pdo): BackupService
    {
        return new BackupService($pdo, new AuditLogRepository($pdo), new BackupLayout($this->backups), $this->uploads, 14);
    }

    private function extractor(): BackupExtractor
    {
        return new BackupExtractor(new BackupLayout($this->backups), dirname(__DIR__, 4) . '/database/migrations');
    }

    /** @return array<int, string> */
    private function zipEntries(string $path): array
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path) === true);
        $names = [];
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $names[] = (string) $zip->getNameIndex($index);
        }
        $zip->close();

        return $names;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
