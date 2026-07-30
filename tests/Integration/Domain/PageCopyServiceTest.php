<?php

declare(strict_types=1);

namespace Tests\Integration\Domain;

use App\Domain\Notes\NoteEncryptionException;
use App\Domain\PageCopyService;
use App\Domain\User;
use App\Repositories\CategoryRepository;
use App\Repositories\LogRepository;
use App\Repositories\NoteAttachmentRepository;
use App\Repositories\NotebookRepository;
use App\Repositories\NoteContentRepository;
use App\Repositories\PageAttachmentRepository;
use App\Repositories\PageRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\TaskRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\NotFoundException;
use App\Support\UploadStorage;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDatabaseTrait;

final class PageCopyServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PDO $pdo;
    private string $storagePath;
    private UploadStorage $storage;
    private PageCopyService $service;
    private PageRepository $pages;
    private WorkspaceRepository $workspaces;
    private NotebookRepository $notebooks;
    private NoteAttachmentRepository $images;
    private PageAttachmentRepository $files;
    private LogRepository $log;
    private User $owner;
    private User $recipient;

    protected function setUp(): void
    {
        $this->pdo = $this->makeDatabase();
        $this->storagePath = sys_get_temp_dir() . '/page-copy-' . bin2hex(random_bytes(8));
        mkdir($this->storagePath, 0750, true);
        $this->storage = new UploadStorage(dirname($this->storagePath), $this->storagePath);
        $this->pages = new PageRepository($this->pdo);
        $this->workspaces = new WorkspaceRepository($this->pdo);
        $this->notebooks = new NotebookRepository($this->pdo);
        $this->images = new NoteAttachmentRepository($this->pdo);
        $this->files = new PageAttachmentRepository($this->pdo);
        $this->log = new LogRepository($this->pdo);
        $this->service = new PageCopyService(
            $this->pdo,
            $this->pages,
            $this->workspaces,
            $this->notebooks,
            new NoteContentRepository($this->pdo),
            $this->images,
            $this->files,
            new CategoryRepository($this->pdo),
            new TaskRepository($this->pdo),
            $this->log,
            new SettingsRepository($this->pdo),
            $this->storage,
        );
        $this->owner = $this->makeUser('owner@example.com');
        $this->recipient = $this->makeUser('recipient@example.com');
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

    public function testEncryptedNoteCannotBeCopiedServerSide(): void
    {
        $source = $this->pages->create($this->workspaceId($this->owner), 'note', 'Geheim', null);
        $this->pdo->prepare('UPDATE pages SET is_encrypted = 1 WHERE id = :id')
            ->execute(['id' => (int) $source['id']]);

        try {
            $this->service->copyFromShare($this->recipient, [
                'mode' => 'read_copy',
                'page_id' => (int) $source['id'],
            ], null);
            self::fail('Verschlüsselte Notiz wurde kopiert.');
        } catch (NoteEncryptionException $exception) {
            self::assertSame('NOTE_ENCRYPTED', $exception->errorCode);
        }
    }

    public function testCopiesOnlyReferencedImagesAndAllFilesIndependently(): void
    {
        $ownerWorkspace = $this->workspaceId($this->owner);
        $sourceNotebook = $this->notebooks->create($ownerWorkspace, 'Quelle', 'quelle', 0);
        $source = $this->pages->create($ownerWorkspace, 'note', 'Original', 'file-text', (int) $sourceNotebook['id']);
        $sourceId = (int) $source['id'];
        $this->pages->updateFields($sourceId, ['is_favorite' => 1]);

        $usedToken = str_repeat('a', 64);
        $unusedToken = str_repeat('b', 64);
        $usedStorage = $this->storage->writeImage($sourceId, 'used-image', 'png');
        $unusedStorage = $this->storage->writeImage($sourceId, 'unused-image', 'png');
        $this->images->create($sourceId, hash('sha256', $usedToken), $usedStorage, 'used.png', 'image/png', 10, 1, 1, $this->owner->id);
        $this->images->create($sourceId, hash('sha256', $unusedToken), $unusedStorage, 'unused.png', 'image/png', 12, 1, 1, $this->owner->id);

        $fileStorage = $this->storage->writeFile($sourceId, 'attachment-data');
        $sourceFileTokenHash = hash('sha256', str_repeat('c', 64));
        $this->files->create($sourceId, $sourceFileTokenHash, $fileStorage, 'report.txt', 'text/plain', 15, $this->owner->id);
        $document = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'image', 'attrs' => ['src' => '/api/attachments/' . $usedToken]]],
            ]],
        ];
        $this->pdo->prepare('UPDATE note_contents SET content = :content, content_text = :text, version = 8 WHERE page_id = :id')
            ->execute(['content' => json_encode($document, JSON_THROW_ON_ERROR), 'text' => 'Current text', 'id' => $sourceId]);
        $this->pdo->prepare('INSERT INTO note_versions (page_id, content, created_at, created_by) VALUES (?, ?, ?, ?)')
            ->execute([$sourceId, '{}', gmdate('Y-m-d\TH:i:s.v\Z'), $this->owner->id]);
        $this->pdo->prepare('INSERT INTO share_links (page_id, token_hash, permission, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$sourceId, hash('sha256', 'source-share'), 'read', gmdate('Y-m-d\TH:i:s.v\Z')]);

        $copy = $this->service->copyFromShare($this->recipient, ['page_id' => $sourceId, 'mode' => 'read_copy'], null);
        $copyId = (int) $copy['id'];
        $copyImages = $this->images->listForPage($copyId);
        $copyFiles = $this->files->listForPage($copyId);
        $copyContent = (new NoteContentRepository($this->pdo))->find($copyId);

        self::assertSame('Original', $copy['title']);
        self::assertNull($copy['notebook_id']);
        self::assertSame(0, (int) $copy['is_favorite']);
        self::assertCount(1, $copyImages);
        self::assertCount(1, $copyFiles);
        self::assertNotSame($usedStorage, $copyImages[0]['storage_name']);
        self::assertNotSame($fileStorage, $copyFiles[0]['storage_name']);
        self::assertNotSame(hash('sha256', $usedToken), $copyImages[0]['token_hash']);
        self::assertNotSame($sourceFileTokenHash, $copyFiles[0]['token_hash']);
        self::assertSame('used-image', file_get_contents((string) $this->storage->path((string) $copyImages[0]['storage_name'])));
        self::assertSame('attachment-data', file_get_contents((string) $this->storage->path((string) $copyFiles[0]['storage_name'])));
        self::assertIsArray($copyContent);
        self::assertSame(1, (int) $copyContent['version']);
        self::assertSame('Current text', $copyContent['content_text']);
        self::assertStringNotContainsString($usedToken, (string) $copyContent['content']);
        self::assertSame(0, $this->pageRowCount('note_versions', $copyId));
        self::assertSame(0, $this->pageRowCount('share_links', $copyId));
    }

    public function testDeepCopiesTaskFieldsAndResetsIdentityMetadata(): void
    {
        $source = $this->pages->create($this->workspaceId($this->owner), 'task', 'Board', null);
        $sourceId = (int) $source['id'];
        $categories = new CategoryRepository($this->pdo);
        $category = $categories->createCopy($sourceId, 'Doing', '#123456', 4, 2);
        $this->pdo->prepare('INSERT INTO import_batches (page_id, category_id, created_by) VALUES (?, ?, ?)')
            ->execute([$sourceId, $category['id'], $this->owner->id]);
        $batchId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO tasks (category_id, title, description, responsible, link, position, is_done, due_date, priority, import_batch_id, version, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $category['id'], 'Ship', 'Details', 'Owner', 'https://example.com', 6, 1, '2026-08-01', 'high', $batchId, 9,
            gmdate('Y-m-d\TH:i:s.v\Z'), gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);

        $copy = $this->service->copyFromShare($this->recipient, ['page_id' => $sourceId, 'permission' => 'read_copy'], null);
        $copiedCategories = $categories->listForPage((int) $copy['id']);
        $copiedTasks = (new TaskRepository($this->pdo))->listForCategory((int) $copiedCategories[0]['id']);

        self::assertCount(1, $copiedCategories);
        self::assertSame(['Doing', '#123456', 4, 2], [
            $copiedCategories[0]['name'], $copiedCategories[0]['color'],
            (int) $copiedCategories[0]['position'], (int) $copiedCategories[0]['wip_limit'],
        ]);
        self::assertCount(1, $copiedTasks);
        self::assertSame('Ship', $copiedTasks[0]['title']);
        self::assertSame('Details', $copiedTasks[0]['description']);
        self::assertSame('Owner', $copiedTasks[0]['responsible']);
        self::assertSame('https://example.com', $copiedTasks[0]['link']);
        self::assertSame(6, (int) $copiedTasks[0]['position']);
        self::assertSame(1, (int) $copiedTasks[0]['is_done']);
        self::assertSame('2026-08-01', $copiedTasks[0]['due_date']);
        self::assertSame('high', $copiedTasks[0]['priority']);
        self::assertNull($copiedTasks[0]['import_batch_id']);
        self::assertSame(1, (int) $copiedTasks[0]['version']);
    }

    public function testCopiesLogColumnsEntriesAndValuesWithoutTheDefaultColumnLeaking(): void
    {
        $source = $this->pages->create($this->workspaceId($this->owner), 'log', 'Fahrtenbuch', null);
        $sourceId = (int) $source['id'];
        // `PageRepository::create` legt für ein neues Logbuch automatisch eine
        // Textspalte an; hier wird sie durch die eigentlichen Spalten ersetzt.
        foreach ($this->log->columnsForPage($sourceId) as $defaultColumn) {
            $this->log->deleteColumn((int) $defaultColumn['id']);
        }
        $place = $this->log->createColumn($sourceId, 'Ort', 'location', 0);
        $hours = $this->log->createColumn($sourceId, 'Dauer', 'hours', 1);

        $entryId = $this->log->createEntry($sourceId, '2026-07-29T09:00:00.000Z', $this->owner->id);
        $this->log->setValue($entryId, (int) $place['id'], ['text' => 'Stuttgart', 'number' => null, 'lat' => 48.775846, 'lon' => 9.182932]);
        $this->log->setValue($entryId, (int) $hours['id'], ['text' => null, 'number' => 2.5, 'lat' => null, 'lon' => null]);

        $copy = $this->service->copyFromShare($this->recipient, ['page_id' => $sourceId, 'permission' => 'read_copy'], null);
        $copyId = (int) $copy['id'];

        $copiedColumns = $this->log->columnsForPage($copyId);
        self::assertCount(2, $copiedColumns);
        self::assertSame(['Ort', 'Dauer'], array_column($copiedColumns, 'name'));

        $copiedEntries = $this->log->entriesForPage($copyId, null, false);
        self::assertCount(1, $copiedEntries);
        self::assertSame('2026-07-29T09:00:00.000Z', $copiedEntries[0]['occurred_at']);

        $newPlaceId = (int) $copiedColumns[0]['id'];
        $newHoursId = (int) $copiedColumns[1]['id'];
        self::assertSame('Stuttgart', $copiedEntries[0]['values'][$newPlaceId]['value_text']);
        self::assertSame(48.775846, (float) $copiedEntries[0]['values'][$newPlaceId]['value_lat']);
        self::assertSame(2.5, (float) $copiedEntries[0]['values'][$newHoursId]['value_number']);
        // Der Eintrag gehört jetzt dem Empfänger, nicht mehr dem ursprünglichen Ersteller.
        self::assertSame($this->recipient->id, (int) $copiedEntries[0]['created_by']);
    }

    public function testRejectsNotebookOwnedByAnotherUserWithoutCreatingPage(): void
    {
        $source = $this->pages->create($this->workspaceId($this->owner), 'note', 'Source', null);
        $foreignNotebook = $this->notebooks->create($this->workspaceId($this->owner), 'Foreign', 'foreign', 0);
        $before = $this->recipientPageCount();

        try {
            $this->service->copyFromShare(
                $this->recipient,
                ['page_id' => $source['id'], 'mode' => 'read_copy'],
                (int) $foreignNotebook['id'],
            );
            self::fail('A foreign notebook must be rejected.');
        } catch (NotFoundException) {
            self::assertSame($before, $this->recipientPageCount());
        }
    }

    public function testMissingSourceFileRollsBackDatabaseAndWrittenImages(): void
    {
        $source = $this->pages->create($this->workspaceId($this->owner), 'note', 'Broken', null);
        $sourceId = (int) $source['id'];
        $token = str_repeat('d', 64);
        $imageStorage = $this->storage->writeImage($sourceId, 'image-data', 'png');
        $this->images->create($sourceId, hash('sha256', $token), $imageStorage, null, 'image/png', 10, 1, 1, $this->owner->id);
        $document = ['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['src' => '/api/attachments/' . $token]]]];
        $this->pdo->prepare('UPDATE note_contents SET content = ? WHERE page_id = ?')
            ->execute([json_encode($document, JSON_THROW_ON_ERROR), $sourceId]);
        $missingStorage = $this->storage->writeFile($sourceId, 'will-disappear');
        $this->files->create($sourceId, hash('sha256', str_repeat('e', 64)), $missingStorage, 'gone.bin', 'application/octet-stream', 14, $this->owner->id);
        $this->storage->delete($missingStorage);
        $storedBefore = glob($this->storagePath . '/notes/*/*') ?: [];

        try {
            $this->service->copyFromShare($this->recipient, ['page_id' => $sourceId, 'mode' => 'read_copy'], null);
            self::fail('A missing source file must fail the copy.');
        } catch (NotFoundException) {
            self::assertSame(0, $this->recipientPageCount());
            self::assertSame($storedBefore, glob($this->storagePath . '/notes/*/*') ?: []);
        }
    }

    private function makeUser(string $email): User
    {
        $stmt = $this->pdo->prepare('INSERT INTO users (google_sub, email, name, created_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$email, $email, $email, gmdate('Y-m-d\TH:i:s.v\Z')]);
        $id = (int) $this->pdo->lastInsertId();
        $this->workspaces->createForUser($id);

        return new User($id, $email, $email, $email, null, true, false);
    }

    private function workspaceId(User $user): int
    {
        $id = $this->workspaces->findByUserId($user->id);
        self::assertIsInt($id);

        return $id;
    }

    private function recipientPageCount(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM pages WHERE workspace_id = ?');
        $stmt->execute([$this->workspaceId($this->recipient)]);

        return (int) $stmt->fetchColumn();
    }

    private function pageRowCount(string $table, int $pageId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE page_id = ?");
        $stmt->execute([$pageId]);

        return (int) $stmt->fetchColumn();
    }
}
