<?php

declare(strict_types=1);

namespace Tests\Integration\Domain;

use App\Domain\AdminService;
use App\Domain\Notes\ImageCompressionService;
use App\Domain\Notes\PageAttachmentService;
use App\Domain\Notes\ProseMirrorValidator;
use App\Domain\PageService;
use App\Domain\SharedNotebooksException;
use App\Domain\User;
use App\Repositories\AdminRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\NoteAttachmentRepository;
use App\Repositories\PageRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\NotFoundException;
use App\Support\UploadStorage;
use App\Support\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDatabaseTrait;

final class AdminServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PDO $pdo;
    private AdminService $admin;
    private PageService $pages;
    private string $uploadRoot;
    private UploadStorage $storage;
    private User $adminUser;
    private User $member;

    protected function setUp(): void
    {
        $this->pdo = $this->makeDatabase();
        $workspaces = new WorkspaceRepository($this->pdo);
        $this->pages = new PageService(new PageRepository($this->pdo), $workspaces);

        $this->uploadRoot = sys_get_temp_dir() . '/shareinfo-admin-test-' . bin2hex(random_bytes(6));
        $this->storage = new UploadStorage($this->uploadRoot, 'uploads');
        $imageCompression = new ImageCompressionService(
            $this->pages,
            new NoteAttachmentRepository($this->pdo),
            $this->storage,
        );

        $this->admin = new AdminService(
            $this->pdo,
            new AdminRepository($this->pdo),
            new AuditLogRepository($this->pdo),
            $this->storage,
            new ProseMirrorValidator(),
            new SettingsRepository($this->pdo),
            $imageCompression,
        );

        $this->adminUser = $this->makeUser($workspaces, 'admin@example.com', true);
        $this->member = $this->makeUser($workspaces, 'member@example.com', false);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->uploadRoot)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->uploadRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->uploadRoot);
    }

    public function testOverviewReportsPagesAndStorage(): void
    {
        $page = $this->pages->create($this->member, 'note', 'Mit Bild', null);
        $this->attach((int) $page['id'], 'token-a', 4096);

        $overview = $this->admin->overview();
        $member = $this->userRow($overview['users'], $this->member->id);

        self::assertSame(1, $member['page_count']);
        self::assertSame(1, $member['attachment_count']);
        self::assertSame(1, $member['image_count']);
        self::assertSame(4096, $member['attachment_bytes']);
        self::assertGreaterThan(4096, $member['total_bytes']);
        self::assertSame(2, $overview['totals']['user_count']);
    }

    public function testDeleteUserRemovesAllContentAndFiles(): void
    {
        $page = $this->pages->create($this->member, 'note', 'Mit Bild', null);
        $storageName = $this->attach((int) $page['id'], 'token-a', 128);
        self::assertFileExists($this->uploadRoot . '/uploads/' . $storageName);

        $result = $this->admin->deleteUser($this->adminUser, $this->member->id, 'ip');

        self::assertSame(1, $result['deleted_files']);
        self::assertFileDoesNotExist($this->uploadRoot . '/uploads/' . $storageName);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM users WHERE id = ' . $this->member->id));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM pages'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM note_contents'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM note_attachments'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM workspaces WHERE user_id = ' . $this->member->id));
    }

    public function testDeactivatingRevokesSessionsAndDeviceTokensButKeepsContent(): void
    {
        $this->pages->create($this->member, 'note', 'Bleibt', null);
        $this->pdo->exec(
            "INSERT INTO sessions (user_id, token_hash, expires_at) VALUES ({$this->member->id}, 'h1', '2099-01-01T00:00:00Z')"
        );
        $this->pdo->exec(
            "INSERT INTO device_tokens (user_id, label, token_hash, created_at)
             VALUES ({$this->member->id}, 'Handy', 't1', '2026-01-01T00:00:00Z')"
        );

        $result = $this->admin->setUserActive($this->adminUser, $this->member->id, false, 'ip');

        self::assertFalse($result['is_active']);
        self::assertSame(1, $result['sessions']);
        self::assertSame(1, $result['device_tokens']);
        self::assertSame(0, $this->scalar("SELECT is_active FROM users WHERE id = {$this->member->id}"));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM sessions WHERE revoked_at IS NULL'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM pages'));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'user_deactivated'"));

        $this->admin->setUserActive($this->adminUser, $this->member->id, true, 'ip');
        self::assertSame(1, $this->scalar("SELECT is_active FROM users WHERE id = {$this->member->id}"));
    }

    public function testDeactivatingOwnAccountIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->admin->setUserActive($this->adminUser, $this->adminUser->id, false, 'ip');
    }

    public function testTransferMovesNotebooksAndCollectsLoosePages(): void
    {
        $workspaces = new WorkspaceRepository($this->pdo);
        $memberWorkspace = (int) $workspaces->findByUserId($this->member->id);
        $adminWorkspace = (int) $workspaces->findByUserId($this->adminUser->id);
        $this->pdo->exec(
            "INSERT INTO notebooks (workspace_id, name, name_key) VALUES ({$memberWorkspace}, 'Baustelle', 'baustelle')"
        );
        $notebookId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO notebooks (workspace_id, name, name_key) VALUES ({$adminWorkspace}, 'Baustelle', 'baustelle')"
        );
        $this->pages->create($this->member, 'note', 'Im Buch', null, null);
        $this->pdo->exec("UPDATE pages SET notebook_id = {$notebookId} WHERE title = 'Im Buch'");
        $this->pages->create($this->member, 'task', 'Lose', null);
        // Der Empfänger war bisher Teilnehmer - danach ist er Eigentümer.
        $this->pdo->exec("INSERT INTO notebook_shares (user_id, notebook_id) VALUES ({$this->adminUser->id}, {$notebookId})");

        $result = $this->admin->transferContent($this->adminUser, $this->member->id, $this->adminUser->id, 'ip');

        self::assertSame(2, $result['notebooks']);
        self::assertSame(2, $result['pages']);
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM pages WHERE workspace_id = {$memberWorkspace}"));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM notebook_shares'));
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM pages WHERE notebook_id IS NULL"));
        $names = $this->pdo->query("SELECT name FROM notebooks WHERE workspace_id = {$adminWorkspace} ORDER BY name");
        self::assertNotFalse($names);
        self::assertSame(
            ['Baustelle', 'Baustelle (von member@example.com)', 'Übernommen von member@example.com'],
            $names->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    public function testUserCanDeleteOwnAccountAfterConfirmingTheEmail(): void
    {
        $this->pages->create($this->member, 'note', 'Privat', null);

        try {
            $this->admin->deleteOwnAccount($this->member, 'falsch@example.com', false, 'ip');
            self::fail('Ohne passende E-Mail darf nichts gelöscht werden.');
        } catch (ValidationException) {
        }

        $this->admin->deleteOwnAccount($this->member, 'MEMBER@example.com', false, 'ip');

        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM users WHERE id = {$this->member->id}"));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM pages'));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'account_deleted' AND user_id IS NULL"));
    }

    public function testDeletingOwnAccountWithSharedNotebooksNeedsExplicitConsent(): void
    {
        $workspace = (int) (new WorkspaceRepository($this->pdo))->findByUserId($this->member->id);
        $this->pdo->exec("INSERT INTO notebooks (workspace_id, name, name_key) VALUES ({$workspace}, 'Team', 'team')");
        $notebookId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO notebook_shares (user_id, notebook_id) VALUES ({$this->adminUser->id}, {$notebookId})");

        try {
            $this->admin->deleteOwnAccount($this->member, 'member@example.com', false, 'ip');
            self::fail('Geteilte Notizbücher hätten eine Bestätigung verlangen müssen.');
        } catch (SharedNotebooksException $e) {
            self::assertSame(1, $e->count);
        }

        $this->admin->deleteOwnAccount($this->member, 'member@example.com', true, 'ip');
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM notebooks'));
    }

    public function testDeletingOwnAccountIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->admin->deleteUser($this->adminUser, $this->adminUser->id, 'ip');
    }

    public function testDeletingUnknownUserFails(): void
    {
        $this->expectException(NotFoundException::class);
        $this->admin->deleteUser($this->adminUser, 9999, 'ip');
    }

    public function testOrphanDetectionKeepsReferencedAttachments(): void
    {
        $page = $this->pages->create($this->member, 'note', 'Mit Bild', null);
        $used = $this->attach((int) $page['id'], 'token-used', 100);
        $orphan = $this->attach((int) $page['id'], 'token-orphan', 200);

        $this->setNoteContent((int) $page['id'], 'token-used');

        $overview = $this->admin->overview();
        self::assertSame(1, $overview['orphans']['count']);
        self::assertSame(200, $overview['orphans']['bytes']);

        $result = $this->admin->purgeOrphanedAttachments($this->adminUser, 'ip');

        self::assertSame(1, $result['count']);
        self::assertFileExists($this->uploadRoot . '/uploads/' . $used);
        self::assertFileDoesNotExist($this->uploadRoot . '/uploads/' . $orphan);
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM note_attachments'));
    }

    public function testAttachmentInOldVersionIsNotOrphaned(): void
    {
        $page = $this->pages->create($this->member, 'note', 'Mit Bild', null);
        $this->attach((int) $page['id'], 'token-old', 100);
        $this->setNoteContent((int) $page['id'], 'token-current');

        $statement = $this->pdo->prepare(
            'INSERT INTO note_versions (page_id, content) VALUES (:page, :content)'
        );
        $statement->execute([
            'page' => (int) $page['id'],
            'content' => $this->documentJson('token-old'),
        ]);

        self::assertSame(0, $this->admin->overview()['orphans']['count']);
    }

    public function testQuotaDefaultsAndPerUserOverride(): void
    {
        self::assertSame(0, $this->admin->defaultQuotaMb());

        $this->admin->setDefaultQuotaMb($this->adminUser, 500, 'ip');
        self::assertSame(500, $this->admin->defaultQuotaMb());

        $overview = $this->admin->overview();
        self::assertSame(500, $this->userRow($overview['users'], $this->member->id)['effective_quota_mb']);
        self::assertNull($this->userRow($overview['users'], $this->member->id)['storage_quota_mb']);

        $this->admin->setUserQuotaMb($this->adminUser, $this->member->id, 50, 'ip');
        $overview = $this->admin->overview();
        self::assertSame(50, $this->userRow($overview['users'], $this->member->id)['effective_quota_mb']);

        $this->admin->setUserQuotaMb($this->adminUser, $this->member->id, null, 'ip');
        self::assertSame(500, $this->userRow($this->admin->overview()['users'], $this->member->id)['effective_quota_mb']);
    }

    public function testNegativeQuotaIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->admin->setDefaultQuotaMb($this->adminUser, -1, 'ip');
    }

    public function testOfflineAttachmentLimitDefaultsAndIsConfigurable(): void
    {
        self::assertSame(
            PageAttachmentService::DEFAULT_OFFLINE_MAX_KB,
            $this->admin->offlineAttachmentMaxKb(),
        );
        self::assertSame(
            PageAttachmentService::DEFAULT_OFFLINE_MAX_KB,
            $this->admin->overview()['offline_attachment_max_kb'],
        );

        $this->admin->setOfflineAttachmentMaxKb($this->adminUser, 1024, 'ip');

        self::assertSame(1024, $this->admin->offlineAttachmentMaxKb());
        self::assertSame(1024, $this->admin->overview()['offline_attachment_max_kb']);
    }

    /** 0 schaltet das automatische Vorladen ab und muss erlaubt bleiben. */
    public function testOfflineAttachmentLimitAcceptsZero(): void
    {
        self::assertSame(0, $this->admin->setOfflineAttachmentMaxKb($this->adminUser, 0, 'ip'));
        self::assertSame(0, $this->admin->offlineAttachmentMaxKb());
    }

    public function testOfflineAttachmentLimitAboveMaximumIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->admin->setOfflineAttachmentMaxKb(
            $this->adminUser,
            PageAttachmentService::MAX_OFFLINE_MAX_KB + 1,
            'ip',
        );
    }

    public function testAdminCompressesAllImagesForUserWithScreenPreset(): void
    {
        $page = $this->pages->create($this->member, 'note', 'Großes Bild', null);
        $image = imagecreatetruecolor(2200, 1100);
        self::assertInstanceOf(\GdImage::class, $image);
        $color = imagecolorallocate($image, 40, 120, 190);
        self::assertIsInt($color);
        imagefill($image, 0, 0, $color);
        ob_start();
        self::assertTrue(imagejpeg($image, null, 100));
        $bytes = ob_get_clean();
        imagedestroy($image);
        self::assertIsString($bytes);

        $storageName = $this->storage->writeImage((int) $page['id'], $bytes, 'jpg');
        $statement = $this->pdo->prepare(
            "INSERT INTO note_attachments
                (page_id, token_hash, storage_name, mime_type, byte_size, width, height)
             VALUES (:page, 'admin-compress-token', :storage, 'image/jpeg', :bytes, 2200, 1100)"
        );
        $statement->execute([
            'page' => (int) $page['id'],
            'storage' => $storageName,
            'bytes' => strlen($bytes),
        ]);

        $result = $this->admin->compressUserImages($this->adminUser, $this->member->id, 'ip');
        $path = $this->storage->path($storageName);
        self::assertNotNull($path);
        $info = getimagesize($path);

        self::assertSame(1, $result['compressed']);
        self::assertGreaterThan(0, $result['saved_bytes']);
        self::assertIsArray($info);
        self::assertSame(1960, $info[0]);
        self::assertSame(980, $info[1]);
    }

    private function documentJson(string $token): string
    {
        return json_encode([
            'type' => 'doc',
            'content' => [[
                'type' => 'image',
                'attrs' => ['src' => '/api/attachments/' . hash('sha256', $token)],
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function setNoteContent(int $pageId, string $token): void
    {
        $statement = $this->pdo->prepare('UPDATE note_contents SET content = :content WHERE page_id = :page');
        $statement->execute(['content' => $this->documentJson($token), 'page' => $pageId]);
    }

    /**
     * Legt einen Anhang samt Datei an. Der „Token" wird - wie im Betrieb - nur
     * als Hash gespeichert; im Dokument steht der Hash als Dateiname.
     */
    private function attach(int $pageId, string $token, int $bytes): string
    {
        $storage = new UploadStorage($this->uploadRoot, 'uploads');
        $storageName = $storage->writeImage($pageId, str_repeat('x', $bytes), 'png');

        $statement = $this->pdo->prepare(
            'INSERT INTO note_attachments (page_id, token_hash, storage_name, mime_type, byte_size, width, height)
             VALUES (:page, :hash, :storage, :mime, :bytes, 10, 10)'
        );
        $statement->execute([
            'page' => $pageId,
            'hash' => hash('sha256', hash('sha256', $token)),
            'storage' => $storageName,
            'mime' => 'image/png',
            'bytes' => $bytes,
        ]);

        return $storageName;
    }

    /**
     * @param array<int, array<string, mixed>> $users
     * @return array<string, mixed>
     */
    private function userRow(array $users, int $userId): array
    {
        foreach ($users as $user) {
            if ($user['id'] === $userId) {
                return $user;
            }
        }

        self::fail("Nutzer #{$userId} fehlt in der Übersicht.");
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);

        return $statement !== false ? (int) $statement->fetchColumn() : -1;
    }

    private function makeUser(WorkspaceRepository $workspaces, string $email, bool $isAdmin): User
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

        return new User($id, $email, $email, $email, null, true, $isAdmin);
    }
}
