<?php

declare(strict_types=1);

namespace Tests\Integration\Domain\Import;

use App\Domain\Import\ArchiveChunkStore;
use App\Domain\Import\MarkdownConverter;
use App\Domain\Import\ZipImportService;
use App\Domain\NotebookService;
use App\Domain\Notes\AttachmentService;
use App\Domain\Notes\NoteService;
use App\Domain\Notes\PageAttachmentService;
use App\Domain\Notes\ProseMirrorValidator;
use App\Domain\PageService;
use App\Domain\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\NoteAttachmentRepository;
use App\Repositories\NotebookRepository;
use App\Repositories\NoteContentRepository;
use App\Repositories\NoteVersionRepository;
use App\Repositories\PageAttachmentRepository;
use App\Repositories\PageRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\ShareRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\UploadStorage;
use App\Support\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\Support\InMemoryDatabaseTrait;
use ZipArchive;

final class ZipImportServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    /** 1×1-PNG, damit finfo und getimagesizefromstring ein echtes Bild sehen. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private PDO $pdo;
    private ZipImportService $import;
    private PageService $pages;
    private NoteService $notes;
    private User $user;
    private string $workRoot;

    protected function setUp(): void
    {
        $this->pdo = $this->makeDatabase();
        $this->workRoot = sys_get_temp_dir() . '/shareinfo-import-test-' . bin2hex(random_bytes(6));
        mkdir($this->workRoot . '/uploads', 0775, true);

        $workspaces = new WorkspaceRepository($this->pdo);
        $pageRepository = new PageRepository($this->pdo);
        $notebooks = new NotebookService($this->pdo, new NotebookRepository($this->pdo), $workspaces);
        $this->pages = new PageService($pageRepository, $workspaces, new ShareRepository($this->pdo), $notebooks);
        $settings = new SettingsRepository($this->pdo);
        $storage = new UploadStorage($this->workRoot, 'uploads');
        $noteAttachments = new NoteAttachmentRepository($this->pdo);
        $pageAttachments = new PageAttachmentRepository($this->pdo);

        $this->notes = new NoteService(
            $this->pdo,
            $this->pages,
            $pageRepository,
            new NoteContentRepository($this->pdo),
            new NoteVersionRepository($this->pdo),
            $noteAttachments,
            new ProseMirrorValidator(),
        );

        $this->import = new ZipImportService(
            $this->pages,
            $this->notes,
            new AttachmentService($this->pages, $noteAttachments, $storage, 10, $settings, 0, $pageAttachments),
            new PageAttachmentService($this->pages, $pageAttachments, $noteAttachments, $storage, $settings),
            $pageRepository,
            $notebooks,
            new MarkdownConverter(),
            new AuditLogRepository($this->pdo),
        );

        $statement = $this->pdo->prepare(
            'INSERT INTO users (google_sub, email, name, created_at) VALUES (:sub, :email, :name, :now)'
        );
        $statement->execute([
            'sub' => 'sub-1',
            'email' => 'owner@example.com',
            'name' => 'Owner',
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
        $userId = (int) $this->pdo->lastInsertId();
        $workspaces->createForUser($userId);
        $this->user = new User($userId, 'sub-1', 'owner@example.com', 'Owner', null, true, false);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workRoot);
    }

    public function testImportsNotesWithImagesAndFileAttachments(): void
    {
        $archive = $this->makeArchive([
            'Export/Reise.md' => "---\ndate: 2024-05-06 10:11:12\ncreated: 2024-05-01 08:00:00\n---\n\n"
                . "# Reise\n\nText mit ![Karte](Files/karte.png) und [Handbuch](Files/handbuch.pdf).\n",
            'Export/Files/karte.png' => base64_decode(self::PNG),
            'Export/Files/handbuch.pdf' => '%PDF-1.4 Testinhalt',
        ]);

        $report = $this->import->import($this->user, $archive, 'iphash')->toArray();

        self::assertSame(1, $report['pages']);
        self::assertSame(1, $report['images']);
        self::assertSame(1, $report['files']);
        self::assertSame(0, $report['failed_count']);
        self::assertSame(0, $report['skipped_count']);

        $pages = $this->pages->list($this->user, 'updated', null, false);
        self::assertCount(1, $pages);
        self::assertSame('Reise', $pages[0]['title']);
        self::assertSame(1, $pages[0]['attachment_count']);

        $content = $this->notes->get($this->user, (int) $pages[0]['id']);
        $encoded = json_encode($content['content'], JSON_UNESCAPED_SLASHES);
        self::assertIsString($encoded);
        // Bild als Knoten aus dem eigenen Anhangspeicher, Dateianhang als Text.
        self::assertMatchesRegularExpression('#"src":"/api/attachments/[a-f0-9]{64}"#', $encoded);
        self::assertStringContainsString('Handbuch', $encoded);
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM note_attachments'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM page_attachments'));
    }

    /** Der Seitentitel steht über dem Inhalt; die gleichlautende Überschrift entfällt. */
    public function testLeadingHeadingMatchingTheTitleIsRemoved(): void
    {
        $archive = $this->makeArchive([
            'Notizen/Einkauf.md' => "## Einkauf\n\nMilch und Brot\n",
            'Notizen/Anderes.md' => "## Ganz anderer Kopf\n\nText\n",
        ]);

        $this->import->import($this->user, $archive, 'iphash');

        self::assertSame('Milch und Brot', $this->contentTextOf('Einkauf'));
        self::assertStringStartsWith('Ganz anderer Kopf', $this->contentTextOf('Anderes'));
    }

    public function testTimestampsFromFrontMatterAreApplied(): void
    {
        $archive = $this->makeArchive([
            'Alt.md' => "---\ncreated: 2021-02-03 04:05:06\ndate: 2022-03-04 05:06:07\n---\n\nInhalt\n",
        ]);

        $this->import->import($this->user, $archive, 'iphash');

        $row = $this->row('SELECT created_at, updated_at FROM pages');
        self::assertSame('2021-02-03T04:05:06.000Z', $row['created_at']);
        self::assertSame('2022-03-04T05:06:07.000Z', $row['updated_at']);
        self::assertSame(
            '2022-03-04T05:06:07.000Z',
            (string) $this->row('SELECT updated_at FROM note_contents')['updated_at'],
        );
    }

    /** Auch Programme, Archive und Rohdaten kommen als Anhang mit (FR-NOTE-21). */
    public function testUncommonFileTypesAreImportedAsAttachments(): void
    {
        $archive = $this->makeArchive([
            'Tools.md' => "Programm [setup.exe](Files/setup.exe) und [Daten](Files/config.dat)\n",
            'Files/setup.exe' => "MZ\x90\x00" . str_repeat("\x00", 128),
            'Files/config.dat' => random_bytes(64),
        ]);

        $report = $this->import->import($this->user, $archive, 'iphash')->toArray();

        self::assertSame(1, $report['pages']);
        self::assertSame(2, $report['files']);
        self::assertSame(0, $report['skipped_count']);
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM page_attachments'));
        // Der Verweis bleibt als Text erhalten, damit die Information nicht fehlt.
        self::assertStringContainsString('setup.exe', $this->contentTextOf('Tools'));
    }

    /** Zu große Anhänge bleiben ein Grund zum Überspringen - mit Meldung. */
    public function testFileAboveTheAdminLimitIsReported(): void
    {
        (new SettingsRepository($this->pdo))->set(PageAttachmentService::MAX_ATTACHMENT_MB_KEY, '1');
        $archive = $this->makeArchive([
            'Gross.md' => "Siehe [Riesig](Files/riesig.bin)\n",
            'Files/riesig.bin' => str_repeat('x', 1024 * 1024 + 10),
        ]);

        $report = $this->import->import($this->user, $archive, 'iphash')->toArray();

        self::assertSame(1, $report['pages']);
        self::assertSame(0, $report['files']);
        self::assertSame(1, $report['skipped_count']);
        self::assertSame('riesig.bin', $report['skipped'][0]['name']);
        self::assertStringContainsString('1 MB', $report['skipped'][0]['reason']);
    }

    public function testDeadResourceLinksAndUnusedFilesAreCounted(): void
    {
        $archive = $this->makeArchive([
            'Notiz.md' => "Text !\\[\\[./_resources/fehlt.png\\]\\] Ende\n",
            'Files/ungenutzt.png' => base64_decode(self::PNG),
        ]);

        $report = $this->import->import($this->user, $archive, 'iphash')->toArray();

        self::assertSame(1, $report['dead_links']);
        self::assertSame(1, $report['unused_files']);
        self::assertSame(0, $report['images']);
    }

    public function testSameImageReferencedTwiceIsStoredOnce(): void
    {
        $archive = $this->makeArchive([
            'Doppelt.md' => "![A](Files/bild.png)\n\n![B](Files/bild.png)\n",
            'Files/bild.png' => base64_decode(self::PNG),
        ]);

        $report = $this->import->import($this->user, $archive, 'iphash')->toArray();

        self::assertSame(1, $report['images']);
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM note_attachments'));
    }

    /** Ein unbrauchbarer Dateiname darf keine Seite ohne Titel erzeugen. */
    public function testTitleFallsBackToTheFirstHeading(): void
    {
        $archive = $this->makeArchive([
            'Ordner/![[_resources.bild.png]].md' => "## Echter Titel\n\nInhalt\n",
        ]);

        $this->import->import($this->user, $archive, 'iphash');

        self::assertSame('Echter Titel', (string) $this->row('SELECT title FROM pages')['title']);
    }

    /**
     * PHP weist zu große Anfragen ab, bevor die Anwendung sie sieht — die
     * Oberfläche muss die kleinste wirksame Grenze vorher kennen.
     */
    public function testMaxUploadBytesRespectsThePhpConfiguration(): void
    {
        $phpLimit = min(
            $this->toBytes((string) ini_get('upload_max_filesize')),
            $this->toBytes((string) ini_get('post_max_size')),
        );

        self::assertSame(
            $phpLimit > 0 ? min($phpLimit, 500 * 1024 * 1024) : 500 * 1024 * 1024,
            $this->import->maxUploadBytes(),
        );
        self::assertArrayHasKey('upload_max_filesize', $this->import->phpUploadLimits());
    }

    private function toBytes(string $value): int
    {
        $number = (int) $value;
        if ($number <= 0) {
            return 0;
        }

        return match (strtolower(substr(trim($value), -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * Der Weg der Oberfläche: Das Archiv kommt in Teilen an und wird erst
     * danach importiert (FR-IMP-25).
     */
    public function testArchiveUploadedInPartsIsImported(): void
    {
        $path = $this->workRoot . '/geteilt.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE) === true);
        $zip->addFromString('Geteilt.md', "# Geteilt\n\nInhalt aus Teilen ![Bild](Files/bild.png)\n");
        $zip->addFromString('Files/bild.png', base64_decode(self::PNG));
        $zip->close();

        $bytes = (string) file_get_contents($path);
        $store = new ArchiveChunkStore($this->workRoot . '/chunks');
        $id = $store->begin($this->user->id, 'geteilt.zip', strlen($bytes), 10 * 1024 * 1024);

        $chunkSize = 64;
        $index = 0;
        foreach (str_split($bytes, $chunkSize) as $part) {
            $store->append($this->user->id, $id, $index, $part);
            ++$index;
        }
        self::assertGreaterThan(1, $index);

        $assembled = $store->finish($this->user->id, $id);
        self::assertSame(hash_file('sha256', $path), hash_file('sha256', $assembled['path']));

        $report = $this->import->importFromPath($this->user, $assembled['path'], 'iphash')->toArray();
        $store->discard($id);

        self::assertSame(1, $report['pages']);
        self::assertSame(1, $report['images']);
        self::assertSame('Geteilt', (string) $this->row('SELECT title FROM pages')['title']);
    }

    public function testUpNoteNotebookLinksBesideNotesAssignTheNotebook(): void
    {
        $archive = $this->makeArchive([
            'UpNote/General Space/Zuordnung.md' => "# Zuordnung\n\nInhalt\n",
            'UpNote/General Space/notebooks/Projekte/Zuordnung.md.lnk' => '',
        ]);

        $this->import->import($this->user, $archive, 'iphash');

        self::assertSame('Projekte', (string) $this->row(
            'SELECT notebooks.name FROM pages JOIN notebooks ON notebooks.id = pages.notebook_id',
        )['name']);
    }

    public function testUpNoteNotebookLinksAssignNotesAndReuseExistingNotebook(): void
    {
        $notebooks = new NotebookService($this->pdo, new NotebookRepository($this->pdo), new WorkspaceRepository($this->pdo));
        $existing = $notebooks->create($this->user, ['name' => '  Reisen  ']);
        $archive = $this->makeArchive([
            'root/General Space/all notes/Verbunden.md' => "Verbundener Inhalt\n",
            'root/General Space/all notes/Frei.md' => "Freier Inhalt\n",
            'root/General Space/all notes/ZuGross.md' => str_repeat('x', 2_000_001),
            'root/General Space/notebooks/reisen/Verbunden.md.lnk' => '',
            'root/General Space/notebooks/Leer/Falsch.md.lnk' => '',
            'root/General Space/notebooks/NichtAnlegen/ZuGross.md.lnk' => '',
        ]);

        $this->import->import($this->user, $archive, 'iphash');

        self::assertSame((int) $existing['id'], (int) $this->row("SELECT notebook_id FROM pages WHERE title = 'Verbunden'")['notebook_id']);
        self::assertNull($this->row("SELECT notebook_id FROM pages WHERE title = 'Frei'")['notebook_id']);
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM notebooks'));
    }

    public function testChunkImportUsesUpNoteNotebookLinks(): void
    {
        $path = $this->workRoot . '/upnote-chunked.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE) === true);
        $zip->addFromString('root/General Space/all notes/Geteilt.md', "Inhalt\n");
        $zip->addFromString('root/General Space/notebooks/Archiv/Geteilt.md.lnk', '');
        $zip->close();

        $report = $this->import->importFromPath($this->user, $path, 'iphash')->toArray();

        self::assertSame(1, $report['pages']);
        self::assertSame('Archiv', (string) $this->row('SELECT name FROM notebooks')['name']);
        self::assertNotNull($this->row("SELECT notebook_id FROM pages WHERE title = 'Geteilt'")['notebook_id']);
    }

    public function testArchiveWithoutMarkdownIsRejected(): void
    {
        $archive = $this->makeArchive(['liesmich.txt' => 'nichts zu holen']);

        $this->expectException(ValidationException::class);
        $this->import->import($this->user, $archive, 'iphash');
    }

    public function testNonZipUploadIsRejected(): void
    {
        $stream = new StreamFactory()->createStream('kein zip');
        $upload = new UploadedFile($stream, 'test.zip', 'application/zip', 8, UPLOAD_ERR_OK);

        $this->expectException(ValidationException::class);
        $this->import->import($this->user, $upload, 'iphash');
    }

    public function testEmptyUploadIsRejected(): void
    {
        $stream = new StreamFactory()->createStream('');
        $upload = new UploadedFile($stream, 'leer.zip', 'application/zip', 0, UPLOAD_ERR_OK);

        $this->expectException(ValidationException::class);
        $this->import->import($this->user, $upload, 'iphash');
    }

    public function testImportIsLogged(): void
    {
        $this->import->import($this->user, $this->makeArchive(['A.md' => "Text\n"]), 'iphash');

        $row = $this->row("SELECT action, metadata FROM audit_log WHERE action = 'notes_imported'");
        self::assertStringContainsString('"pages":1', (string) $row['metadata']);
    }

    /** @param array<string, string> $files */
    private function makeArchive(array $files): UploadedFile
    {
        $path = $this->workRoot . '/archive-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE) === true);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return new UploadedFile(
            new StreamFactory()->createStreamFromFile($path),
            basename($path),
            'application/zip',
            (int) filesize($path),
            UPLOAD_ERR_OK,
        );
    }

    private function contentTextOf(string $title): string
    {
        $statement = $this->pdo->prepare(
            'SELECT n.content_text FROM note_contents n JOIN pages p ON p.id = n.page_id WHERE p.title = :title'
        );
        $statement->execute(['title' => $title]);

        return trim((string) $statement->fetchColumn());
    }

    /** @return array<string, mixed> */
    private function row(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);
        $row = $statement->fetch();
        self::assertIsArray($row);

        return $row;
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);

        return $statement !== false ? (int) $statement->fetchColumn() : -1;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : @unlink($full);
        }
        @rmdir($path);
    }
}
