<?php

declare(strict_types=1);

namespace Tests\Integration\Domain\Backup;

use App\Domain\Backup\BackupLayout;
use App\Domain\Backup\BackupRestorer;
use App\Domain\Backup\BackupService;
use App\Domain\User;
use App\Repositories\AuditLogRepository;
use App\Support\NotFoundException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDatabaseTrait;
use ZipArchive;

final class BackupServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private string $root;
    private string $uploads;
    private string $backups;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/backup-test-' . bin2hex(random_bytes(8));
        $this->uploads = $this->root . '/uploads';
        $this->backups = $this->root . '/backups';
        mkdir($this->uploads . '/notes/1', 0770, true);
        mkdir($this->uploads . '/files/1', 0770, true);
        $this->pdo = $this->makeDatabase();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testCreatesCompleteSnapshotOfDatabaseAndUploads(): void
    {
        $this->writeUpload('notes/1/a.jpg', 'bild-a');
        $this->writeUpload('files/1/b.bin', 'datei-b');

        $result = $this->service()->create();

        self::assertSame(2, $result['upload_count']);
        self::assertSame(2, $result['new_files']);
        self::assertGreaterThan(0, $result['database_bytes']);

        $manifest = $this->layout()->manifest($result['id']);
        self::assertSame(2, $manifest['uploads']['count']);
        self::assertArrayHasKey('pages', $manifest['tables']);
        self::assertFileExists($this->backups . '/' . $manifest['database']['file']);
    }

    /** Der zweite Lauf darf keine unveränderte Datei erneut ablegen. */
    public function testSecondRunStoresNothingNew(): void
    {
        $this->writeUpload('notes/1/a.jpg', 'bild-a');
        $service = $this->service();
        $service->create();

        $second = $service->create();

        self::assertSame(1, $second['upload_count']);
        self::assertSame(0, $second['new_files']);
    }

    /** Identischer Inhalt unter zwei Namen belegt den Pool nur einmal. */
    public function testIdenticalFilesAreStoredOnce(): void
    {
        $this->writeUpload('notes/1/a.jpg', 'gleicher-inhalt');
        $this->writeUpload('notes/1/b.jpg', 'gleicher-inhalt');

        $result = $this->service()->create();

        self::assertSame(2, $result['upload_count']);
        self::assertSame(1, $result['new_files']);
    }

    /**
     * Der wichtigste Fall: Die Bildkompression schreibt über `rename` neuen
     * Inhalt unter denselben Pfad. Der ältere Snapshot muss den alten Stand
     * behalten - sonst würde eine Sicherung nachträglich verfälscht.
     */
    public function testInPlaceReplacementKeepsOlderSnapshotIntact(): void
    {
        $this->writeUpload('notes/1/a.jpg', 'original-gross');
        $service = $this->service();
        $first = $service->create();

        $this->writeUpload('notes/1/a.jpg', 'komprimiert');
        $second = $service->create();

        self::assertSame(1, $second['new_files'], 'Der geänderte Inhalt muss neu gesichert werden.');

        $restorer = $this->restorer();
        self::assertTrue($restorer->verify($first['id'])['ok']);
        self::assertTrue($restorer->verify($second['id'])['ok']);

        $oldEntry = $this->layout()->manifest($first['id'])['uploads']['files'][0];
        $newEntry = $this->layout()->manifest($second['id'])['uploads']['files'][0];
        self::assertNotSame($oldEntry['sha256'], $newEntry['sha256']);
        self::assertSame('original-gross', file_get_contents($this->layout()->poolPath($oldEntry['sha256'])));
        self::assertSame('komprimiert', file_get_contents($this->layout()->poolPath($newEntry['sha256'])));
    }

    public function testHalfWrittenUploadsAreIgnored(): void
    {
        $this->writeUpload('notes/1/a.jpg', 'fertig');
        $this->writeUpload('notes/1/a.jpg.tmp-abc123', 'halb');

        $result = $this->service()->create();

        self::assertSame(1, $result['upload_count']);
    }

    public function testPruneRemovesOldestSnapshotsAndCollectsPool(): void
    {
        $this->writeUpload('notes/1/a.jpg', 'erste-fassung');
        $service = $this->service(keep: 1);
        $first = $service->create();

        $this->writeUpload('notes/1/a.jpg', 'zweite-fassung');
        $second = $service->create();

        self::assertSame(1, $second['pruned']);
        self::assertSame(1, $second['collected_files'], 'Der alte Inhalt wird nicht mehr referenziert.');
        self::assertFalse($this->layout()->exists($first['id']));
        self::assertTrue($this->layout()->exists($second['id']));
        self::assertTrue($this->restorer()->verify($second['id'])['ok']);
    }

    /** Solange ein Snapshot den Inhalt braucht, darf die GC ihn nicht anfassen. */
    public function testGarbageCollectionKeepsReferencedContent(): void
    {
        $this->writeUpload('notes/1/a.jpg', 'bleibt');
        $service = $this->service(keep: 5);
        $first = $service->create();
        $this->writeUpload('notes/1/b.jpg', 'dazu');
        $service->create();

        self::assertTrue($this->restorer()->verify($first['id'])['ok']);
    }

    public function testArchiveContainsDatabaseAndUploads(): void
    {
        $this->writeUpload('notes/1/a.jpg', 'bild-a');
        $service = $this->service();
        $created = $service->create();

        $archive = $service->archive($created['id']);

        self::assertFileExists($archive['path']);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive['path']) === true);
        self::assertNotFalse($zip->locateName('db/app.sqlite'));
        self::assertNotFalse($zip->locateName('manifest.json'));
        self::assertSame('bild-a', $zip->getFromName('uploads/notes/1/a.jpg'));
        $zip->close();
    }

    public function testDeletedSnapshotIsGone(): void
    {
        $this->writeUpload('notes/1/a.jpg', 'bild-a');
        $service = $this->service();
        $created = $service->create();

        $service->delete($created['id'], $this->admin(), null);

        self::assertFalse($this->layout()->exists($created['id']));
    }

    public function testUnknownSnapshotIsRejected(): void
    {
        $this->expectException(NotFoundException::class);

        $this->layout()->manifest('../../etc/passwd');
    }

    private function service(int $keep = 14): BackupService
    {
        return new BackupService(
            $this->pdo,
            new AuditLogRepository($this->pdo),
            $this->layout(),
            $this->uploads,
            $keep,
        );
    }

    private function restorer(): BackupRestorer
    {
        return new BackupRestorer($this->layout(), $this->root . '/app.sqlite', $this->uploads);
    }

    private function layout(): BackupLayout
    {
        return new BackupLayout($this->backups);
    }

    private function admin(): User
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (google_sub, email, name, created_at) VALUES (:sub, :email, :name, :now)'
        );
        $statement->execute([
            'sub' => 'sub-1',
            'email' => 'a@example.com',
            'name' => 'A',
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);

        return new User((int) $this->pdo->lastInsertId(), 'sub-1', 'a@example.com', 'A', null, true, true);
    }

    private function writeUpload(string $relative, string $contents): void
    {
        $path = $this->uploads . '/' . $relative;
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0770, true);
        }
        file_put_contents($path, $contents);
        // Die Prüfsummen-Abkürzung vergleicht Größe und mtime; im Test müssen
        // Änderungen deshalb sichtbar in der Zeit auseinanderliegen.
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
