<?php

declare(strict_types=1);

namespace Tests\Integration\Domain\Notes;

use App\Domain\Notes\PageAttachmentService;
use App\Domain\PageService;
use App\Domain\User;
use App\Repositories\NoteAttachmentRepository;
use App\Repositories\PageAttachmentRepository;
use App\Repositories\PageRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\ShareRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\ForbiddenException;
use App\Support\NotFoundException;
use App\Support\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\Support\InMemoryDatabaseTrait;

final class PageAttachmentServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PDO $pdo;
    private PageAttachmentService $attachments;
    private PageService $pages;
    private SettingsRepository $settings;
    private ShareRepository $shares;
    private string $uploadRoot;
    private User $owner;
    private User $other;
    private int $notePageId;

    protected function setUp(): void
    {
        $this->pdo = $this->makeDatabase();
        $workspaces = new WorkspaceRepository($this->pdo);
        $this->shares = new ShareRepository($this->pdo);
        $this->pages = new PageService(new PageRepository($this->pdo), $workspaces, $this->shares);
        $this->settings = new SettingsRepository($this->pdo);

        $this->uploadRoot = sys_get_temp_dir() . '/shareinfo-files-test-' . bin2hex(random_bytes(6));

        $this->attachments = new PageAttachmentService(
            $this->pages,
            new PageAttachmentRepository($this->pdo),
            new NoteAttachmentRepository($this->pdo),
            new \App\Support\UploadStorage($this->uploadRoot, 'uploads'),
            $this->settings,
        );

        $this->owner = $this->makeUser($workspaces, 'owner@example.com');
        $this->other = $this->makeUser($workspaces, 'other@example.com');

        $page = $this->pages->create($this->owner, 'note', 'Notiz mit Anhang', null);
        $this->notePageId = (int) $page['id'];
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->uploadRoot);
    }

    public function testUploadStoresFileAndMetadata(): void
    {
        $attachment = $this->upload('%PDF-1.4 Testinhalt', 'Vertrag.pdf');

        self::assertSame('Vertrag.pdf', $attachment['name']);
        self::assertSame('application/pdf', $attachment['mime_type']);
        self::assertTrue($attachment['is_pdf']);
        self::assertSame('/api/page-attachments/' . $attachment['id'], $attachment['url']);
        self::assertCount(1, $this->attachments->listForPage($this->owner, $this->notePageId));

        $file = $this->attachments->open($this->owner, $attachment['id']);
        self::assertFileExists($file['path']);
        self::assertSame('Vertrag.pdf', $file['original_name']);
    }

    /**
     * Jeder Dateityp ist anhängbar (FR-NOTE-21); den Schutz trägt die
     * Auslieferung, die alles außer PDF nur als Download herausgibt.
     */
    public function testAnyFileTypeIsAccepted(): void
    {
        $script = $this->upload("<?php echo 'x';", 'shell.php');
        $archive = $this->upload("7z\xBC\xAF\x27\x1C" . str_repeat("\x00", 32), 'daten.7z');

        self::assertFalse($script['is_pdf']);
        self::assertFalse($archive['is_pdf']);
        self::assertSame('shell.php', $script['name']);
        self::assertCount(2, $this->attachments->listForPage($this->owner, $this->notePageId));
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM page_attachments'));
    }

    /** Ohne erkennbaren Typ bleibt ein neutraler stehen statt einer Ablehnung. */
    public function testUnknownContentGetsNeutralMimeType(): void
    {
        $attachment = $this->upload(random_bytes(64), 'unbekannt.bin');

        self::assertSame('application/octet-stream', $attachment['mime_type']);
    }

    public function testFileLargerThanAdminLimitIsRejected(): void
    {
        $this->settings->set(PageAttachmentService::MAX_ATTACHMENT_MB_KEY, '1');
        self::assertSame(1, $this->attachments->maxAttachmentMb());

        $this->expectException(ValidationException::class);
        $this->upload('%PDF-1.4 ' . str_repeat('x', 1024 * 1024 + 10), 'gross.pdf');
    }

    /**
     * Die Grenze steuert nur das Vorladen im Client (FR-OFFLINE-06); Uploads
     * bleiben davon unberührt.
     */
    public function testOfflineAttachmentLimitIsReportedInBytes(): void
    {
        self::assertSame(
            PageAttachmentService::DEFAULT_OFFLINE_MAX_KB * 1024,
            $this->attachments->offlineAttachmentMaxBytes(),
        );

        $this->settings->set(SettingsRepository::OFFLINE_ATTACHMENT_MAX_KB, '512');
        self::assertSame(512 * 1024, $this->attachments->offlineAttachmentMaxBytes());

        $this->settings->set(SettingsRepository::OFFLINE_ATTACHMENT_MAX_KB, '0');
        self::assertSame(0, $this->attachments->offlineAttachmentMaxBytes());
    }

    /** Die Seitenliste meldet die Zahl der Anhänge; der Offline-Prefetch spart sich sonst die Abfrage. */
    public function testPageListReportsAttachmentCount(): void
    {
        $listed = $this->pages->list($this->owner, 'updated', null, false);
        self::assertSame(0, $listed[0]['attachment_count']);

        $this->upload('%PDF-1.4 Inhalt', 'anhang.pdf');

        $listed = $this->pages->list($this->owner, 'updated', null, false);
        self::assertSame(1, $listed[0]['attachment_count']);
    }

    public function testQuotaIsEnforcedAcrossAttachments(): void
    {
        // 1 MB Kontingent, zwei Anhänge von je 700 KB: der zweite passt nicht.
        $statement = $this->pdo->prepare('UPDATE users SET storage_quota_mb = 1 WHERE id = :id');
        $statement->execute(['id' => $this->owner->id]);

        $this->upload('%PDF-1.4 ' . str_repeat('a', 700 * 1024), 'eins.pdf');

        $this->expectException(ValidationException::class);
        $this->upload('%PDF-1.4 ' . str_repeat('b', 700 * 1024), 'zwei.pdf');
    }

    /**
     * Das Kontingent gilt für Bilder und Dateianhänge gemeinsam. Zählte der
     * Bild-Upload nur seinen eigenen Speicher, ließe sich die Grenze über
     * Dateianhänge aushebeln.
     */
    public function testAttachmentStorageCountsAgainstImageQuota(): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET storage_quota_mb = 1 WHERE id = :id');
        $statement->execute(['id' => $this->owner->id]);

        // Der Anhang füllt das Kontingent bis auf weniger als die Bildgröße auf.
        $png = $this->pngBytes();
        $remaining = intdiv(strlen($png), 2);
        $this->upload(str_pad('%PDF-1.4 ', (1024 * 1024) - $remaining, 'a'), 'gross.pdf');

        $images = new \App\Domain\Notes\AttachmentService(
            $this->pages,
            new NoteAttachmentRepository($this->pdo),
            new \App\Support\UploadStorage($this->uploadRoot, 'uploads'),
            10,
            $this->settings,
            0,
            new PageAttachmentRepository($this->pdo),
        );

        $this->expectException(ValidationException::class);
        $images->upload($this->owner, $this->notePageId, $this->uploadedFile($png, 'bild.png'));
    }

    /** Kleinstes gültiges PNG, damit die Bildprüfung greift. */
    private function pngBytes(): string
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );
        self::assertNotFalse($png);

        return $png;
    }

    public function testFilenameIsStrippedOfPathSegments(): void
    {
        $attachment = $this->upload('%PDF-1.4 x', '../../etc/passwd.pdf');

        self::assertSame('....etcpasswd.pdf', $attachment['name']);
    }

    public function testStrangerCanNeitherReadNorDelete(): void
    {
        $attachment = $this->upload('%PDF-1.4 x', 'privat.pdf');

        try {
            $this->attachments->open($this->other, $attachment['id']);
            self::fail('Fremde dürfen den Anhang nicht öffnen.');
        } catch (NotFoundException) {
            // Erwartet: Die Seite ist für den Fremden nicht sichtbar.
        }

        $this->expectException(NotFoundException::class);
        $this->attachments->delete($this->other, $attachment['id']);
    }

    public function testReadOnlyShareMayViewButNotDelete(): void
    {
        $attachment = $this->upload('%PDF-1.4 x', 'geteilt.pdf');

        $token = bin2hex(random_bytes(32));
        $shareId = $this->shares->create($this->notePageId, hash('sha256', $token), 'read');
        $this->shares->recordAccess($this->other->id, $shareId);

        $file = $this->attachments->open($this->other, $attachment['id']);
        self::assertSame('geteilt.pdf', $file['original_name']);

        $this->expectException(ForbiddenException::class);
        $this->attachments->delete($this->other, $attachment['id']);
    }

    public function testDeleteRemovesRowAndFile(): void
    {
        $attachment = $this->upload('%PDF-1.4 x', 'weg.pdf');
        $path = $this->attachments->open($this->owner, $attachment['id'])['path'];

        $this->attachments->delete($this->owner, $attachment['id']);

        self::assertFileDoesNotExist($path);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM page_attachments'));
    }

    public function testTaskPagesRejectAttachments(): void
    {
        $taskPage = $this->pages->create($this->owner, 'task', 'Aufgaben', null);

        $this->expectException(NotFoundException::class);
        $this->attachments->upload($this->owner, (int) $taskPage['id'], $this->uploadedFile('%PDF-1.4 x', 'a.pdf'));
    }

    /** @return array<string, mixed> */
    private function upload(string $contents, string $filename): array
    {
        return $this->attachments->upload($this->owner, $this->notePageId, $this->uploadedFile($contents, $filename));
    }

    private function uploadedFile(string $contents, string $filename): UploadedFile
    {
        $stream = new StreamFactory()->createStream($contents);

        return new UploadedFile($stream, $filename, null, strlen($contents), UPLOAD_ERR_OK);
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
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : @unlink($full);
        }
        @rmdir($path);
    }

    private function makeUser(WorkspaceRepository $workspaces, string $email): User
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (google_sub, email, name, created_at) VALUES (:sub, :email, :name, :now)'
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
