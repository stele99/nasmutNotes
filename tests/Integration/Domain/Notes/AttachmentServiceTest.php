<?php

declare(strict_types=1);

namespace Tests\Integration\Domain\Notes;

use App\Domain\Notes\AttachmentService;
use App\Domain\Notes\ImageCompressionService;
use App\Domain\Notes\NoteEncryptionException;
use App\Domain\PageService;
use App\Domain\ShareService;
use App\Domain\User;
use App\Repositories\NoteAttachmentRepository;
use App\Repositories\PageRepository;
use App\Repositories\ShareRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\UploadStorage;
use App\Support\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\UploadedFile;
use Tests\Support\InMemoryDatabaseTrait;

final class AttachmentServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PDO $pdo;
    private string $storagePath;
    private AttachmentService $attachments;
    private ImageCompressionService $compression;
    private ShareService $shares;
    private PageService $pages;
    private User $owner;
    private User $recipient;
    private int $pageId;

    protected function setUp(): void
    {
        $this->pdo = $this->makeDatabase();
        $this->storagePath = sys_get_temp_dir() . '/shareinfo-attachments-' . bin2hex(random_bytes(8));
        mkdir($this->storagePath, 0750, true);

        $workspaces = new WorkspaceRepository($this->pdo);
        $shareRepository = new ShareRepository($this->pdo);
        $this->pages = new PageService(new PageRepository($this->pdo), $workspaces, $shareRepository);
        $this->shares = new ShareService($this->pages, $shareRepository);
        $attachmentRepository = new NoteAttachmentRepository($this->pdo);
        $storage = new UploadStorage(dirname($this->storagePath), $this->storagePath);
        $this->attachments = new AttachmentService(
            $this->pages,
            $attachmentRepository,
            $storage,
            1,
        );
        $this->compression = new ImageCompressionService(
            $this->pages,
            $attachmentRepository,
            $storage,
        );

        $this->owner = $this->makeUser($workspaces, 'owner@example.com');
        $this->recipient = $this->makeUser($workspaces, 'recipient@example.com');
        $page = $this->pages->create($this->owner, 'note', 'Screenshots', null);
        $this->pageId = (int) $page['id'];
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->storagePath)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storagePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($this->storagePath);
    }

    public function testOwnerCanUploadAndOpenPng(): void
    {
        $bytes = $this->pngBytes();
        $uploaded = $this->attachments->upload(
            $this->owner,
            $this->pageId,
            $this->uploadedFile($bytes, 'screenshot.png'),
        );

        $opened = $this->attachments->open($this->owner, $uploaded['token']);

        self::assertSame('image/png', $uploaded['mime_type']);
        self::assertSame(1, $uploaded['width']);
        self::assertSame(1, $uploaded['height']);
        self::assertFileExists($opened['path']);
        self::assertSame($bytes, file_get_contents($opened['path']));
    }

    public function testWriteShareCanUploadImage(): void
    {
        $share = $this->shares->create($this->owner, $this->pageId, 'write');
        $this->shares->open($this->recipient, $share['token']);

        $uploaded = $this->attachments->upload(
            $this->recipient,
            $this->pageId,
            $this->uploadedFile($this->pngBytes(), 'shared.png'),
        );

        self::assertSame('image/png', $uploaded['mime_type']);
    }

    public function testPublicReadShareCannotBecomeAuthenticatedWriteAccess(): void
    {
        $share = $this->shares->create($this->owner, $this->pageId, 'read');
        $this->expectException(ValidationException::class);
        $this->shares->open($this->recipient, $share['token']);
    }

    public function testRejectsNonImageUpload(): void
    {
        $this->expectException(ValidationException::class);
        $this->attachments->upload(
            $this->owner,
            $this->pageId,
            $this->uploadedFile('not an image', 'fake.png'),
        );
    }

    public function testEncryptedNoteRejectsImageBeforeWritingFile(): void
    {
        $this->pdo->prepare('UPDATE pages SET is_encrypted = 1 WHERE id = :id')
            ->execute(['id' => $this->pageId]);

        try {
            $this->attachments->upload(
                $this->owner,
                $this->pageId,
                $this->uploadedFile($this->pngBytes(), 'secret.png'),
            );
            self::fail('Bild wurde an eine verschlüsselte Notiz angehängt.');
        } catch (NoteEncryptionException $exception) {
            self::assertSame('NOTE_ENCRYPTED', $exception->errorCode);
        }
    }

    public function testCompressionValidatesQualityBeforeUsingGd(): void
    {
        $this->expectException(ValidationException::class);
        $this->compression->compress($this->owner, $this->pageId, 10, 'screen');
    }

    public function testCompressionIsRestrictedToTheOwner(): void
    {
        $share = $this->shares->create($this->owner, $this->pageId, 'write');
        $this->shares->open($this->recipient, $share['token']);

        $this->expectException(\App\Support\NotFoundException::class);
        $this->compression->compress($this->recipient, $this->pageId, 82, 'screen');
    }

    public function testCompressesAndResizesJpegWithGd(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD ist in dieser lokalen PHP-Laufzeit nicht installiert.');
        }

        $image = imagecreatetruecolor(2200, 1100);
        self::assertInstanceOf(\GdImage::class, $image);
        $color = imagecolorallocate($image, 28, 110, 180);
        self::assertIsInt($color);
        imagefill($image, 0, 0, $color);
        ob_start();
        self::assertTrue(imagejpeg($image, null, 100));
        $bytes = ob_get_clean();
        imagedestroy($image);
        self::assertIsString($bytes);

        $uploaded = $this->attachments->upload(
            $this->owner,
            $this->pageId,
            $this->uploadedFile($bytes, 'gross.jpg'),
        );
        $result = $this->compression->compress($this->owner, $this->pageId, 75, 'small');
        $opened = $this->attachments->open($this->owner, $uploaded['token']);
        $info = getimagesize($opened['path']);

        self::assertSame(1, $result['compressed']);
        self::assertGreaterThan(0, $result['saved_bytes']);
        self::assertIsArray($info);
        self::assertSame(800, $info[0]);
        self::assertSame(400, $info[1]);
        self::assertSame(filesize($opened['path']), $opened['byte_size']);
    }

    private function uploadedFile(string $bytes, string $name): UploadedFile
    {
        $path = $this->storagePath . '/input-' . bin2hex(random_bytes(6));
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, strlen($bytes), UPLOAD_ERR_OK, false);
    }

    private function pngBytes(): string
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($bytes);

        return $bytes;
    }

    private function makeUser(WorkspaceRepository $workspaces, string $email): User
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (google_sub, email, name, created_at)
             VALUES (:sub, :email, :name, :created_at)'
        );
        $stmt->execute([
            'sub' => $email,
            'email' => $email,
            'name' => $email,
            'created_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
        $userId = (int) $this->pdo->lastInsertId();
        $workspaces->createForUser($userId);

        return new User($userId, $email, $email, $email, null, true, false);
    }
}
