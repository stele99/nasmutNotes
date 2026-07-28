<?php

declare(strict_types=1);

namespace Tests\Integration\Domain\Backup;

use App\Domain\Backup\BackupLayout;
use App\Domain\Backup\BackupRestorer;
use App\Domain\Backup\BackupService;
use App\Repositories\AuditLogRepository;
use App\Support\Database;
use App\Support\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Der Restore arbeitet auf echten Dateien, deshalb hier eine SQLite-Datei auf
 * der Platte statt der In-Memory-Datenbank (AK-43).
 */
final class BackupRestorerTest extends TestCase
{
    private string $root;
    private string $uploads;
    private string $backups;
    private string $databasePath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/restore-test-' . bin2hex(random_bytes(8));
        $this->uploads = $this->root . '/uploads';
        $this->backups = $this->root . '/backups';
        $this->databasePath = $this->root . '/data/app.sqlite';
        mkdir($this->uploads . '/notes/1', 0770, true);
        mkdir(dirname($this->databasePath), 0770, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testRestoreBringsBackDeletedFileAndDatabaseRow(): void
    {
        $this->writeUpload('notes/1/a.jpg', 'bild-a');
        $pdo = $this->database();
        $pdo->exec(
            "INSERT INTO users (google_sub, email, name, created_at)
             VALUES ('sub-1', 'vorher@example.com', 'Vorher', '2026-01-01T00:00:00Z')"
        );
        $snapshot = $this->service($pdo)->create();
        unset($pdo);

        // Nach der Sicherung: Datei weg, Datenbank verändert.
        unlink($this->uploads . '/notes/1/a.jpg');
        $live = Database::connect($this->databasePath);
        $live->exec('DELETE FROM users');
        unset($live);

        $report = $this->restorer()->restore($snapshot['id']);

        self::assertSame(1, $report['restored_files']);
        self::assertSame('bild-a', file_get_contents($this->uploads . '/notes/1/a.jpg'));

        $restored = Database::connect($this->databasePath);
        $statement = $restored->query('SELECT email FROM users');
        self::assertNotFalse($statement);
        self::assertSame('vorher@example.com', $statement->fetchColumn());
    }

    /**
     * Kern der inhaltsadressierten Ablage: Ein später überschriebenes Bild muss
     * aus dem älteren Snapshot in seiner damaligen Fassung zurückkommen.
     */
    public function testRestoringOlderSnapshotReturnsOldFileContent(): void
    {
        $this->writeUpload('notes/1/a.jpg', 'fassung-eins');
        $pdo = $this->database();
        $service = $this->service($pdo);
        $first = $service->create();

        $this->writeUpload('notes/1/a.jpg', 'fassung-zwei');
        $service->create();
        unset($pdo, $service);

        $this->restorer()->restore($first['id']);

        self::assertSame('fassung-eins', file_get_contents($this->uploads . '/notes/1/a.jpg'));
    }

    public function testUnchangedFilesAreLeftAlone(): void
    {
        $this->writeUpload('notes/1/a.jpg', 'unveraendert');
        $pdo = $this->database();
        $snapshot = $this->service($pdo)->create();
        unset($pdo);

        $report = $this->restorer()->restore($snapshot['id']);

        self::assertSame(0, $report['restored_files']);
        self::assertSame(1, $report['skipped_files']);
    }

    public function testPruneRemovesFilesTheSnapshotDoesNotKnow(): void
    {
        $this->writeUpload('notes/1/a.jpg', 'gesichert');
        $pdo = $this->database();
        $snapshot = $this->service($pdo)->create();
        unset($pdo);

        $this->writeUpload('notes/1/spaeter.jpg', 'nach-der-sicherung');

        $report = $this->restorer()->restore($snapshot['id'], prune: true);

        self::assertSame(1, $report['pruned_files']);
        self::assertFileDoesNotExist($this->uploads . '/notes/1/spaeter.jpg');
    }

    public function testRestoreWithoutPruneKeepsNewerFiles(): void
    {
        $this->writeUpload('notes/1/a.jpg', 'gesichert');
        $pdo = $this->database();
        $snapshot = $this->service($pdo)->create();
        unset($pdo);

        $this->writeUpload('notes/1/spaeter.jpg', 'nach-der-sicherung');

        $this->restorer()->restore($snapshot['id']);

        self::assertFileExists($this->uploads . '/notes/1/spaeter.jpg');
    }

    /** Eine manipulierte Sicherung darf nicht eingespielt werden. */
    public function testCorruptDatabaseSnapshotIsRefused(): void
    {
        $this->writeUpload('notes/1/a.jpg', 'bild-a');
        $pdo = $this->database();
        $snapshot = $this->service($pdo)->create();
        unset($pdo);

        $manifest = (new BackupLayout($this->backups))->manifest($snapshot['id']);
        file_put_contents($this->backups . '/' . $manifest['database']['file'], 'kaputt');

        self::assertFalse($this->restorer()->verify($snapshot['id'])['ok']);

        $this->expectExceptionMessage('beschädigt');
        $this->restorer()->restore($snapshot['id']);
    }

    /**
     * Nach dem Einspielen darf keine WAL-Datei des alten Standes liegen
     * bleiben - SQLite würde sie sonst über den Abzug legen.
     */
    public function testStaleWalFilesAreRemoved(): void
    {
        $this->writeUpload('notes/1/a.jpg', 'bild-a');
        $pdo = $this->database();
        $snapshot = $this->service($pdo)->create();
        unset($pdo);

        file_put_contents($this->databasePath . '-wal', 'alt');
        file_put_contents($this->databasePath . '-shm', 'alt');

        $this->restorer()->restore($snapshot['id']);

        self::assertFileDoesNotExist($this->databasePath . '-wal');
        self::assertFileDoesNotExist($this->databasePath . '-shm');
    }

    private function database(): PDO
    {
        $pdo = Database::connect($this->databasePath);
        new Migrator($pdo, dirname(__DIR__, 4) . '/database/migrations')->migrate();

        return $pdo;
    }

    private function service(PDO $pdo): BackupService
    {
        return new BackupService(
            $pdo,
            new AuditLogRepository($pdo),
            new BackupLayout($this->backups),
            $this->uploads,
            14,
        );
    }

    private function restorer(): BackupRestorer
    {
        return new BackupRestorer(new BackupLayout($this->backups), $this->databasePath, $this->uploads);
    }

    private function writeUpload(string $relative, string $contents): void
    {
        $path = $this->uploads . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0770, true);
        }
        file_put_contents($path, $contents);
        touch($path, time() + random_int(1, 1000));
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
