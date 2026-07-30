<?php

declare(strict_types=1);

namespace Tests\Integration\Domain\Notes;

use App\Domain\Notes\NoteContentException;
use App\Domain\Notes\NoteEncryptionException;
use App\Domain\Notes\NoteService;
use App\Domain\Notes\ProseMirrorValidator;
use App\Domain\Notes\VersionConflictException;
use App\Domain\PageService;
use App\Domain\ShareService;
use App\Domain\User;
use App\Repositories\NoteAttachmentRepository;
use App\Repositories\NoteContentRepository;
use App\Repositories\NoteVersionRepository;
use App\Repositories\PageRepository;
use App\Repositories\ShareRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\ForbiddenException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDatabaseTrait;

final class NoteServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PDO $pdo;
    private NoteService $notes;
    private PageService $pages;
    private ShareService $shares;
    private User $user;
    private User $otherUser;
    private int $pageId;

    protected function setUp(): void
    {
        $this->pdo = $this->makeDatabase();
        $workspaces = new WorkspaceRepository($this->pdo);
        $pageRepository = new PageRepository($this->pdo);
        $shareRepository = new ShareRepository($this->pdo);
        $this->pages = new PageService($pageRepository, $workspaces, $shareRepository);
        $this->shares = new ShareService($this->pages, $shareRepository);
        $this->notes = new NoteService(
            $this->pdo,
            $this->pages,
            $pageRepository,
            new NoteContentRepository($this->pdo),
            new NoteVersionRepository($this->pdo),
            new NoteAttachmentRepository($this->pdo),
            new ProseMirrorValidator(),
        );

        $this->user = $this->makeUser($workspaces, 'a@example.com');
        $this->otherUser = $this->makeUser($workspaces, 'b@example.com');
        $page = $this->pages->create($this->user, 'note', 'Notiz', null);
        $this->pageId = (int) $page['id'];
    }

    private function makeUser(WorkspaceRepository $workspaces, string $email): User
    {
        $stmt = $this->pdo->prepare('INSERT INTO users (google_sub, email, name, created_at) VALUES (:sub, :email, :name, :now)');
        $stmt->execute(['sub' => $email, 'email' => $email, 'name' => $email, 'now' => gmdate('Y-m-d\TH:i:s.v\Z')]);
        $id = (int) $this->pdo->lastInsertId();
        $workspaces->createForUser($id);

        return new User($id, $email, $email, $email, null, true, false);
    }

    /** @return array<string, mixed> */
    private function doc(string $text): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    }

    private function setContentUpdatedAt(string $timestamp): void
    {
        $stmt = $this->pdo->prepare('UPDATE note_contents SET updated_at = :updated_at WHERE page_id = :page_id');
        $stmt->execute(['updated_at' => $timestamp, 'page_id' => $this->pageId]);
    }

    private function versionCount(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM note_versions WHERE page_id = :page_id');
        $stmt->execute(['page_id' => $this->pageId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function envelope(?string $wrappedIv = null, ?string $payloadData = null, ?int $pageId = null): array
    {
        return [
            'zk' => 1,
            'binding' => ['page_id' => (string) ($pageId ?? $this->pageId)],
            'kdf' => [
                'algo' => 'PBKDF2-HMAC-SHA256',
                'iterations' => 600_000,
                'salt' => base64_encode(str_repeat('s', 16)),
            ],
            'wrapped_key' => [
                'algo' => 'AES-256-GCM',
                'iv' => $wrappedIv ?? base64_encode(str_repeat('w', 12)),
                'data' => base64_encode(str_repeat('k', 48)),
            ],
            'payload' => [
                'algo' => 'AES-256-GCM',
                'iv' => base64_encode(str_repeat('p', 12)),
                'data' => $payloadData ?? base64_encode(str_repeat('c', 16)),
            ],
        ];
    }

    public function testInitialContentIsEmptyDocAtVersionOne(): void
    {
        $result = $this->notes->get($this->user, $this->pageId);

        self::assertSame(1, $result['version']);
        self::assertSame('doc', $result['content']['type']);
    }

    public function testSaveIncrementsVersionAndPersistsContent(): void
    {
        $result = $this->notes->save($this->user, $this->pageId, $this->doc('Hallo Welt'), 1);

        self::assertSame(2, $result['version']);
        self::assertSame('a@example.com', $result['last_editor_name']);

        $reloaded = $this->notes->get($this->user, $this->pageId);
        self::assertSame(2, $reloaded['version']);
    }

    public function testSavingIdenticalContentDoesNotIncrementVersion(): void
    {
        $first = $this->notes->save($this->user, $this->pageId, $this->doc('Unverändert'), 1);
        $second = $this->notes->save($this->user, $this->pageId, $this->doc('Unverändert'), 2);

        self::assertSame(2, $first['version']);
        self::assertSame(2, $second['version']);
        self::assertSame(2, $this->notes->get($this->user, $this->pageId)['version']);
    }

    public function testStaleVersionThrowsConflictWithCurrentState(): void
    {
        $this->notes->save($this->user, $this->pageId, $this->doc('Erste Änderung'), 1);

        try {
            $this->notes->save($this->user, $this->pageId, $this->doc('Konkurrierende Änderung'), 1);
            self::fail('Erwartete VersionConflictException wurde nicht geworfen.');
        } catch (VersionConflictException $e) {
            self::assertSame(2, $e->currentVersion);
            self::assertNotNull($e->currentUpdatedAt);
            self::assertSame('a@example.com', $e->currentEditorName);
        }
    }

    public function testRejectsAttachmentThatDoesNotBelongToPage(): void
    {
        $this->expectException(NoteContentException::class);

        $this->notes->save($this->user, $this->pageId, [
            'type' => 'doc',
            'content' => [[
                'type' => 'image',
                'attrs' => [
                    'src' => '/api/attachments/' . str_repeat('c', 64),
                    'alt' => 'Screenshot',
                    'title' => null,
                    'width' => 1,
                    'height' => 1,
                ],
            ]],
        ], 1);
    }

    public function testRapidSavesBySameUserDoNotCreateVersions(): void
    {
        $this->notes->save($this->user, $this->pageId, $this->doc('Stand A'), 1);
        $this->notes->save($this->user, $this->pageId, $this->doc('Stand B'), 2);
        $this->notes->save($this->user, $this->pageId, $this->doc('Stand C'), 3);

        self::assertSame(0, $this->versionCount());
    }

    public function testIdleGapCreatesSnapshotOfPreviousContent(): void
    {
        $this->notes->save($this->user, $this->pageId, $this->doc('Vor der Pause'), 1);
        $this->setContentUpdatedAt(gmdate('Y-m-d\TH:i:s.v\Z', time() - 31 * 60));

        $this->notes->save($this->user, $this->pageId, $this->doc('Nach der Pause'), 2);

        $versions = $this->notes->listVersions($this->user, $this->pageId);
        self::assertCount(1, $versions['versions']);
        self::assertTrue($versions['can_restore']);
        self::assertStringContainsString('Vor der Pause', $versions['versions'][0]['preview']);
        self::assertSame('Nach der Pause', $this->notes->get($this->user, $this->pageId)['content']['content'][0]['content'][0]['text']);
    }

    public function testUserChangeCreatesSnapshot(): void
    {
        $this->notes->save($this->user, $this->pageId, $this->doc('Von A'), 1);

        $share = $this->shares->create($this->user, $this->pageId, 'write');
        $this->shares->open($this->otherUser, $share['token']);
        $this->notes->save($this->otherUser, $this->pageId, $this->doc('Von B'), 2);

        $versions = $this->notes->listVersions($this->user, $this->pageId);
        self::assertCount(1, $versions['versions']);
        self::assertStringContainsString('Von A', $versions['versions'][0]['preview']);
        self::assertSame('a@example.com', $versions['versions'][0]['created_by_name']);
    }

    public function testForceSnapshotCreatesVersionEvenWithinIdleWindow(): void
    {
        $this->notes->save($this->user, $this->pageId, $this->doc('Serverstand'), 1);
        $this->notes->save($this->user, $this->pageId, $this->doc('Erzwungener Snapshot'), 2, true);

        $versions = $this->notes->listVersions($this->user, $this->pageId);
        self::assertCount(1, $versions['versions']);
        self::assertStringContainsString('Serverstand', $versions['versions'][0]['preview']);
    }

    public function testRestoreReplacesContentAndSnapshotsCurrent(): void
    {
        $this->notes->save($this->user, $this->pageId, $this->doc('Alt'), 1);
        $this->setContentUpdatedAt(gmdate('Y-m-d\TH:i:s.v\Z', time() - 31 * 60));
        $this->notes->save($this->user, $this->pageId, $this->doc('Aktuell'), 2);

        $versions = $this->notes->listVersions($this->user, $this->pageId);
        $versionId = $versions['versions'][0]['id'];

        $restored = $this->notes->restoreVersion($this->user, $this->pageId, $versionId, 3);

        self::assertSame('Alt', $restored['content']['content'][0]['content'][0]['text']);
        self::assertSame(4, $restored['version']);

        $after = $this->notes->listVersions($this->user, $this->pageId);
        self::assertCount(2, $after['versions']);
        self::assertStringContainsString('Aktuell', $after['versions'][0]['preview']);
    }

    public function testSharedWriterCannotRestore(): void
    {
        $this->notes->save($this->user, $this->pageId, $this->doc('Owner Stand'), 1);
        $this->setContentUpdatedAt(gmdate('Y-m-d\TH:i:s.v\Z', time() - 31 * 60));
        $this->notes->save($this->user, $this->pageId, $this->doc('Später'), 2);

        $share = $this->shares->create($this->user, $this->pageId, 'write');
        $this->shares->open($this->otherUser, $share['token']);

        $versions = $this->notes->listVersions($this->otherUser, $this->pageId);
        self::assertFalse($versions['can_restore']);

        $this->expectException(ForbiddenException::class);
        $this->notes->restoreVersion($this->otherUser, $this->pageId, $versions['versions'][0]['id'], 3);
    }

    public function testRestoreRejectsAStaleCurrentVersion(): void
    {
        $this->notes->save($this->user, $this->pageId, $this->doc('Alt'), 1);
        $this->setContentUpdatedAt(gmdate('Y-m-d\TH:i:s.v\Z', time() - 31 * 60));
        $this->notes->save($this->user, $this->pageId, $this->doc('Zwischenstand'), 2);
        $versionId = $this->notes->listVersions($this->user, $this->pageId)['versions'][0]['id'];
        $this->notes->save($this->user, $this->pageId, $this->doc('Aktuell'), 3);

        try {
            $this->notes->restoreVersion($this->user, $this->pageId, $versionId, 3);
            self::fail('Erwartete VersionConflictException wurde nicht geworfen.');
        } catch (VersionConflictException $e) {
            self::assertSame(4, $e->currentVersion);
            self::assertSame('Aktuell', $e->currentContent['content'][0]['content'][0]['text']);
        }
        self::assertSame(1, $this->versionCount());
    }

    public function testTrashedNoteCannotRestoreVersion(): void
    {
        $this->notes->save($this->user, $this->pageId, $this->doc('Alt'), 1);
        $this->setContentUpdatedAt(gmdate('Y-m-d\TH:i:s.v\Z', time() - 31 * 60));
        $this->notes->save($this->user, $this->pageId, $this->doc('Aktuell'), 2);
        $versionId = $this->notes->listVersions($this->user, $this->pageId)['versions'][0]['id'];
        $this->pages->softDelete($this->user, $this->pageId);

        self::assertFalse($this->notes->listVersions($this->user, $this->pageId)['can_restore']);

        $this->expectException(ForbiddenException::class);
        $this->notes->restoreVersion($this->user, $this->pageId, $versionId, 3);
    }

    public function testPrunesToTwentyVersions(): void
    {
        $this->notes->save($this->user, $this->pageId, $this->doc('Start'), 1);
        $version = 2;

        for ($i = 0; $i < 25; ++$i) {
            $this->setContentUpdatedAt(gmdate('Y-m-d\TH:i:s.v\Z', time() - 31 * 60));
            $this->notes->save($this->user, $this->pageId, $this->doc("Stand {$i}"), $version);
            ++$version;
        }

        self::assertSame(20, $this->versionCount());
    }

    public function testEncryptPurgesPlaintextStateAndSupportsEncryptedSaveRewrapAndDecrypt(): void
    {
        $this->notes->save($this->user, $this->pageId, $this->doc('Alter Klartext'), 1);
        $this->notes->save($this->user, $this->pageId, $this->doc('Aktueller Klartext'), 2, true);
        $this->pdo->prepare(
            'INSERT INTO search_documents
                (workspace_id, object_type, object_id, page_id, title, body, meta)
             SELECT workspace_id, :type, id, id, title, :body, :meta FROM pages WHERE id = :id'
        )->execute([
            'type' => 'page',
            'body' => 'Historischer Klartext',
            'meta' => 'Klartext-Metadaten',
            'id' => $this->pageId,
        ]);
        self::assertSame(1, $this->versionCount());

        $encrypted = $this->notes->transitionEncryption(
            $this->user,
            $this->pageId,
            'encrypt',
            $this->envelope(),
            3,
            'plain',
        );

        self::assertSame('encrypted', $encrypted['encryption_state']);
        self::assertSame(4, $encrypted['version']);
        self::assertSame(0, $this->versionCount());
        $contentStatement = $this->pdo->query('SELECT content_text FROM note_contents WHERE page_id = ' . $this->pageId);
        self::assertNotFalse($contentStatement);
        $row = $contentStatement->fetch();
        self::assertIsArray($row);
        self::assertSame('', $row['content_text']);
        $searchStatement = $this->pdo->query(
            'SELECT body, meta FROM search_documents WHERE page_id = ' . $this->pageId,
        );
        self::assertNotFalse($searchStatement);
        self::assertSame(['body' => '', 'meta' => ''], $searchStatement->fetch());
        $secureDeleteStatement = $this->pdo->query('PRAGMA secure_delete');
        self::assertNotFalse($secureDeleteStatement);
        self::assertSame(1, (int) $secureDeleteStatement->fetchColumn());

        $changedPayload = $this->envelope(payloadData: base64_encode(str_repeat('d', 16)));
        $saved = $this->notes->save($this->user, $this->pageId, $changedPayload, 4, false, 'encrypted');
        self::assertSame(5, $saved['version']);
        self::assertSame(0, $this->versionCount());

        $rewrapped = $changedPayload;
        $rewrapped['wrapped_key']['iv'] = base64_encode(str_repeat('n', 12));
        $rewrapResult = $this->notes->transitionEncryption(
            $this->user,
            $this->pageId,
            'rewrap',
            $rewrapped,
            5,
            'encrypted',
        );
        self::assertSame(6, $rewrapResult['version']);

        $plain = $this->notes->transitionEncryption(
            $this->user,
            $this->pageId,
            'decrypt',
            $this->doc('Wieder Klartext'),
            6,
            'encrypted',
        );
        self::assertSame('plain', $plain['encryption_state']);
        self::assertSame(7, $plain['version']);
        $plainStatement = $this->pdo->query(
            'SELECT content_text FROM note_contents WHERE page_id = ' . $this->pageId,
        );
        self::assertNotFalse($plainStatement);
        self::assertSame('Wieder Klartext', $plainStatement->fetchColumn());
    }

    public function testRewrapRejectsChangedPayload(): void
    {
        $this->notes->transitionEncryption($this->user, $this->pageId, 'encrypt', $this->envelope(), 1, 'plain');

        try {
            $this->notes->transitionEncryption(
                $this->user,
                $this->pageId,
                'rewrap',
                $this->envelope(payloadData: base64_encode(str_repeat('x', 16))),
                2,
                'encrypted',
            );
            self::fail('Ein veränderter Payload wurde als Rewrap akzeptiert.');
        } catch (NoteEncryptionException $exception) {
            self::assertSame('ENCRYPTION_REWRAP_PAYLOAD_CHANGED', $exception->errorCode);
        }
    }

    public function testEncryptAllowsReadAndWriteSharesButRejectsCopySharesAndAttachments(): void
    {
        $share = $this->shares->create($this->user, $this->pageId, 'write');

        $this->notes->transitionEncryption($this->user, $this->pageId, 'encrypt', $this->envelope(), 1, 'plain');

        $this->shares->open($this->otherUser, $share['token']);
        $sharedSave = $this->notes->save(
            $this->otherUser,
            $this->pageId,
            $this->envelope(payloadData: base64_encode(str_repeat('z', 16))),
            2,
            false,
            'encrypted',
        );
        self::assertSame(3, $sharedSave['version']);
        try {
            $this->notes->transitionEncryption($this->otherUser, $this->pageId, 'decrypt', $this->doc('Klartext'), 3, 'encrypted');
            self::fail('Geteilter Schreiber durfte den Verschlüsselungszustand ändern.');
        } catch (NoteEncryptionException $exception) {
            self::assertSame('ENCRYPTION_OWNER_REQUIRED', $exception->errorCode);
        }

        $copyPage = $this->pages->create($this->user, 'note', 'Kopiervorlage', null);
        $copyPageId = (int) $copyPage['id'];
        $this->shares->create($this->user, $copyPageId, 'read_copy');
        try {
            $this->notes->transitionEncryption($this->user, $copyPageId, 'encrypt', $this->envelope(pageId: $copyPageId), 1, 'plain');
            self::fail('Notiz mit Kopierfreigabe wurde verschlüsselt.');
        } catch (NoteEncryptionException $exception) {
            self::assertSame('ENCRYPTION_HAS_COPY_SHARE', $exception->errorCode);
        }

        $attachmentPage = $this->pages->create($this->user, 'note', 'Mit Anhang', null);
        $attachmentPageId = (int) $attachmentPage['id'];
        $this->pdo->prepare(
            'INSERT INTO page_attachments
                (page_id, token_hash, storage_name, original_name, mime_type, byte_size, created_at)
             VALUES (:page, :token, :storage, :name, :mime, 1, :now)'
        )->execute([
            'page' => $attachmentPageId,
            'token' => hash('sha256', 'token'),
            'storage' => 'dummy',
            'name' => 'dummy.txt',
            'mime' => 'text/plain',
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);

        try {
            $this->notes->transitionEncryption($this->user, $attachmentPageId, 'encrypt', $this->envelope(pageId: $attachmentPageId), 1, 'plain');
            self::fail('Notiz mit Anhang wurde verschlüsselt.');
        } catch (NoteEncryptionException $exception) {
            self::assertSame('ENCRYPTION_HAS_ATTACHMENTS', $exception->errorCode);
        }
    }

    public function testVersionEndpointsRejectEncryptedNote(): void
    {
        $this->notes->transitionEncryption($this->user, $this->pageId, 'encrypt', $this->envelope(), 1, 'plain');

        try {
            $this->notes->listVersions($this->user, $this->pageId);
            self::fail('Versionsliste einer verschlüsselten Notiz wurde gelesen.');
        } catch (NoteEncryptionException $exception) {
            self::assertSame('NOTE_ENCRYPTED', $exception->errorCode);
        }
    }

    public function testMigrationCheckRejectsInvalidEncryptionFlag(): void
    {
        $this->expectException(\PDOException::class);
        $this->pdo->prepare('UPDATE pages SET is_encrypted = 2 WHERE id = :id')
            ->execute(['id' => $this->pageId]);
    }
}
