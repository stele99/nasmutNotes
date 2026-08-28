<?php

declare(strict_types=1);

namespace Tests\Integration\Domain\Export;

use App\Domain\Export\MarkdownRenderer;
use App\Domain\Export\NotebookExportService;
use App\Domain\Log\LogService;
use App\Domain\NotebookService;
use App\Domain\Notes\NoteService;
use App\Domain\Notes\ProseMirrorValidator;
use App\Domain\PageService;
use App\Domain\TaskBoardService;
use App\Domain\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\LogRepository;
use App\Repositories\NoteAttachmentRepository;
use App\Repositories\NotebookRepository;
use App\Repositories\NoteContentRepository;
use App\Repositories\NoteVersionRepository;
use App\Repositories\PageAttachmentRepository;
use App\Repositories\PageRepository;
use App\Repositories\ShareRepository;
use App\Repositories\TaskRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\UploadStorage;
use App\Support\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDatabaseTrait;
use ZipArchive;

final class NotebookExportServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PDO $pdo;
    private string $root;
    private string $uploads;
    private WorkspaceRepository $workspaces;
    private PageService $pages;
    private NotebookService $notebooks;
    private NoteService $notes;
    private TaskBoardService $board;
    private NoteAttachmentRepository $imageRepository;
    private PageAttachmentRepository $fileRepository;
    private UploadStorage $storage;
    private NotebookExportService $export;
    private User $user;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/export-test-' . bin2hex(random_bytes(8));
        $this->uploads = $this->root . '/uploads';
        mkdir($this->uploads . '/notes/1', 0770, true);

        $this->pdo = $this->makeDatabase();
        $this->workspaces = new WorkspaceRepository($this->pdo);
        $notebookRepository = new NotebookRepository($this->pdo);
        $pageRepository = new PageRepository($this->pdo);
        $shareRepository = new ShareRepository($this->pdo);
        $contentRepository = new NoteContentRepository($this->pdo);
        $this->imageRepository = new NoteAttachmentRepository($this->pdo);
        $this->fileRepository = new PageAttachmentRepository($this->pdo);
        $categoryRepository = new CategoryRepository($this->pdo);
        $taskRepository = new TaskRepository($this->pdo);

        $this->notebooks = new NotebookService($this->pdo, $notebookRepository, $this->workspaces);
        $this->pages = new PageService($pageRepository, $this->workspaces, $shareRepository, $this->notebooks);
        $this->notes = new NoteService(
            $this->pdo,
            $this->pages,
            $pageRepository,
            $contentRepository,
            new NoteVersionRepository($this->pdo),
            $this->imageRepository,
            new ProseMirrorValidator(),
        );
        $this->board = new TaskBoardService(
            $this->pdo,
            $this->pages,
            $categoryRepository,
            $taskRepository,
            $pageRepository,
        );
        $this->storage = new UploadStorage($this->root, 'uploads');

        $this->export = new NotebookExportService(
            $this->workspaces,
            $notebookRepository,
            $pageRepository,
            $contentRepository,
            $this->imageRepository,
            $this->fileRepository,
            $categoryRepository,
            $taskRepository,
            new LogRepository($this->pdo),
            $this->storage,
            new MarkdownRenderer(),
            new AuditLogRepository($this->pdo),
            $this->root . '/tmp',
        );

        $this->user = $this->makeUser('a@example.com');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testExportsNotesAsMarkdownWithFrontMatter(): void
    {
        $notebook = $this->notebooks->create($this->user, ['name' => 'Arbeit']);
        $page = $this->pages->create($this->user, 'note', 'Mein Protokoll', null, (int) $notebook['id']);
        $this->notes->save($this->user, (int) $page['id'], [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Inhalt der Notiz.']]],
            ],
        ], 1);

        $entries = $this->exportEntries([(int) $notebook['id']]);

        self::assertArrayHasKey('Arbeit/Mein Protokoll.md', $entries);
        $markdown = $entries['Arbeit/Mein Protokoll.md'];
        self::assertStringStartsWith("---\n", $markdown);
        self::assertStringContainsString('title: "Mein Protokoll"', $markdown);
        self::assertStringContainsString('type: note', $markdown);
        self::assertStringContainsString('notebook: "Arbeit"', $markdown);
        self::assertStringContainsString("\n# Mein Protokoll\n", $markdown);
        self::assertStringContainsString('Inhalt der Notiz.', $markdown);
    }

    public function testExportsEncryptedNoteAsEnvelopeJsonInsteadOfEmptyMarkdown(): void
    {
        $notebook = $this->notebooks->create($this->user, ['name' => 'Tresor']);
        $page = $this->pages->create($this->user, 'note', 'Geheim', null, (int) $notebook['id']);
        $pageId = (int) $page['id'];
        $envelope = [
            'zk' => 1,
            'binding' => ['page_id' => (string) $pageId],
            'kdf' => ['algo' => 'PBKDF2-HMAC-SHA256', 'iterations' => 600_000, 'salt' => base64_encode(str_repeat('s', 16))],
            'wrapped_key' => ['algo' => 'AES-256-GCM', 'iv' => base64_encode(str_repeat('w', 12)), 'data' => base64_encode(str_repeat('k', 48))],
            'payload' => ['algo' => 'AES-256-GCM', 'iv' => base64_encode(str_repeat('p', 12)), 'data' => base64_encode(str_repeat('c', 16))],
        ];
        $this->notes->transitionEncryption($this->user, $pageId, 'encrypt', $envelope, 1, 'plain');

        $entries = $this->exportEntries([(int) $notebook['id']]);

        self::assertArrayHasKey('Tresor/Geheim.encrypted-note.json', $entries);
        self::assertArrayNotHasKey('Tresor/Geheim.md', $entries);
        $exported = json_decode($entries['Tresor/Geheim.encrypted-note.json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame((string) $pageId, $exported['original_page_id']);
        self::assertSame($envelope, $exported['envelope']);
    }

    /** Bilder landen im Unterordner und werden relativ verlinkt. */
    public function testEmbeddedImagesGoIntoFilesFolderAndAreLinked(): void
    {
        $notebook = $this->notebooks->create($this->user, ['name' => 'Bilder']);
        $page = $this->pages->create($this->user, 'note', 'Mit Bild', null, (int) $notebook['id']);
        $token = $this->attachImage((int) $page['id'], 'schema.png');

        $this->notes->save($this->user, (int) $page['id'], [
            'type' => 'doc',
            'content' => [
                ['type' => 'image', 'attrs' => ['src' => "/api/attachments/{$token}", 'alt' => 'Schema']],
            ],
        ], 1);

        $entries = $this->exportEntries([(int) $notebook['id']]);

        self::assertArrayHasKey('Bilder/files/schema.png', $entries);
        self::assertSame('PNG-BYTES', $entries['Bilder/files/schema.png']);
        self::assertStringContainsString('![Schema](files/schema.png)', $entries['Bilder/Mit Bild.md']);
    }

    /** Annotierte Bilder: Sidecar-SVG im Archiv und Textliste im Markdown. */
    public function testAnnotatedImagesGetSidecarSvgAndMarkdownLabels(): void
    {
        $notebook = $this->notebooks->create($this->user, ['name' => 'Notizen']);
        $page = $this->pages->create($this->user, 'note', 'Mit Pfeil', null, (int) $notebook['id']);
        $token = $this->attachImage((int) $page['id'], 'foto.png');

        $this->notes->save($this->user, (int) $page['id'], [
            'type' => 'doc',
            'content' => [
                ['type' => 'image', 'attrs' => [
                    'src' => "/api/attachments/{$token}",
                    'alt' => 'Foto',
                    'annotations' => [
                        'v' => 1,
                        'space' => ['w' => 1000, 'h' => 800],
                        'items' => [
                            ['id' => 'abcd1234', 't' => 'line', 'c' => '#e11d48', 'w' => 4, 'x1' => 10, 'y1' => 20, 'x2' => 30, 'y2' => 40, 'head' => 'end'],
                            ['id' => 'text0001', 't' => 'text', 'c' => '#111827', 'x' => 5, 'y' => 6, 's' => 20, 'bw' => 100, 'bh' => 25, 'f' => null, 'text' => 'Falscher Pfad'],
                        ],
                    ],
                ]],
            ],
        ], 1);

        $entries = $this->exportEntries([(int) $notebook['id']]);

        self::assertArrayHasKey('Notizen/files/foto.png', $entries);
        self::assertArrayHasKey('Notizen/files/foto.annotations.svg', $entries);
        $sidecar = $entries['Notizen/files/foto.annotations.svg'];
        self::assertStringStartsWith('<?xml version="1.0"', $sidecar);
        self::assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $sidecar);
        self::assertStringContainsString('viewBox="0 0 1000 800"', $sidecar);

        $markdown = $entries['Notizen/Mit Pfeil.md'];
        self::assertStringContainsString('![Foto](files/foto.png)', $markdown);
        self::assertStringContainsString('_Bildnotizen: 1. Falscher Pfad_', $markdown);
    }

    /** Ohne Annotationen entsteht auch kein Sidecar. */
    public function testPlainImagesGetNoSidecarSvg(): void
    {
        $notebook = $this->notebooks->create($this->user, ['name' => 'Bilder']);
        $page = $this->pages->create($this->user, 'note', 'Ohne Notizen', null, (int) $notebook['id']);
        $token = $this->attachImage((int) $page['id'], 'schlicht.png');

        $this->notes->save($this->user, (int) $page['id'], [
            'type' => 'doc',
            'content' => [
                ['type' => 'image', 'attrs' => ['src' => "/api/attachments/{$token}", 'alt' => 'Foto']],
            ],
        ], 1);

        $entries = $this->exportEntries([(int) $notebook['id']]);

        self::assertArrayHasKey('Bilder/files/schlicht.png', $entries);
        self::assertArrayNotHasKey('Bilder/files/schlicht.annotations.svg', $entries);
        self::assertStringNotContainsString('_Bildnotizen:', $entries['Bilder/Ohne Notizen.md']);
    }

    public function testPageAttachmentsAreExportedAndListed(): void
    {
        $notebook = $this->notebooks->create($this->user, ['name' => 'Doku']);
        $page = $this->pages->create($this->user, 'note', 'Mit Anhang', null, (int) $notebook['id']);
        $this->attachFile((int) $page['id'], 'handbuch.pdf');

        $entries = $this->exportEntries([(int) $notebook['id']]);

        self::assertArrayHasKey('Doku/files/handbuch.pdf', $entries);
        self::assertStringContainsString('## Anhänge', $entries['Doku/Mit Anhang.md']);
        self::assertStringContainsString('- [handbuch.pdf](files/handbuch.pdf)', $entries['Doku/Mit Anhang.md']);
    }

    /** Task-Seiten: Kategorien als Überschriften untereinander, Aufgaben als Checkliste. */
    public function testExportsTaskPageAsMarkdownChecklist(): void
    {
        $notebook = $this->notebooks->create($this->user, ['name' => 'Planung']);
        $page = $this->pages->create($this->user, 'task', 'Packliste', null, (int) $notebook['id']);
        $pageId = (int) $page['id'];

        $kitchen = $this->board->createCategory($this->user, $pageId, 'Küche', null, null);
        $garage = $this->board->createCategory($this->user, $pageId, 'Garage', null, null);

        $this->board->createTask($this->user, (int) $kitchen['id'], 'Topf', null, null, null, true);
        $this->board->createTask($this->user, (int) $kitchen['id'], 'Pfanne', 'Die beschichtete', 'Anna', null);
        $this->board->createTask($this->user, (int) $garage['id'], 'Werkzeug', null, null, null);

        $markdown = $this->exportEntries([(int) $notebook['id']])['Planung/Packliste.md'];

        self::assertStringContainsString('type: task', $markdown);
        self::assertStringContainsString("## Küche\n", $markdown);
        self::assertStringContainsString("## Garage\n", $markdown);
        self::assertStringContainsString('- [x] Topf', $markdown);
        self::assertStringContainsString('- [ ] Pfanne', $markdown);
        self::assertStringContainsString('  Die beschichtete', $markdown);
        self::assertStringContainsString('  Verantwortlich: Anna', $markdown);
        self::assertStringContainsString('- [ ] Werkzeug', $markdown);
        // Reihenfolge: Küche steht vor Garage, wie im Board.
        self::assertLessThan(strpos($markdown, '## Garage'), strpos($markdown, '## Küche'));
    }

    public function testExportsLogPageAsMarkdownTable(): void
    {
        $notebook = $this->notebooks->create($this->user, ['name' => 'Betrieb']);
        $page = $this->pages->create($this->user, 'log', 'Fahrtenbuch', null, (int) $notebook['id']);
        $pageId = (int) $page['id'];

        $log = new LogService(
            $this->pdo,
            $this->pages,
            new PageRepository($this->pdo),
            new LogRepository($this->pdo),
        );
        $place = (int) $log->createColumn($this->user, $pageId, 'Ziel', 'location')['id'];
        $hours = (int) $log->createColumn($this->user, $pageId, 'Dauer', 'hours')['id'];

        $log->createEntry($this->user, $pageId, '2026-07-01T08:00:00+00:00', [
            $place => ['label' => 'Stuttgart', 'lat' => 48.775846, 'lon' => 9.182932],
            $hours => '2,5',
        ]);
        $log->createEntry($this->user, $pageId, '2026-07-02T09:30:00+00:00', [$place => 'München | Zentrum']);

        $markdown = $this->exportEntries([(int) $notebook['id']])['Betrieb/Fahrtenbuch.md'];

        self::assertStringContainsString('type: log', $markdown);
        self::assertStringContainsString('| Zeitpunkt | Eintrag | Ziel | Dauer |', $markdown);
        self::assertStringContainsString('2026-07-01 08:00', $markdown);
        self::assertStringContainsString('Stuttgart (48.77585, 9.18293)', $markdown);
        self::assertStringContainsString('2,50 h', $markdown);
        // Der senkrechte Strich in einem Wert darf die Tabelle nicht sprengen.
        self::assertStringContainsString('München \\| Zentrum', $markdown);
        // Neueste Einträge stehen auch im Export oben.
        self::assertLessThan(strpos($markdown, '2026-07-01'), strpos($markdown, '2026-07-02'));
    }

    public function testSanitizesTitlesThatWouldBecomePaths(): void
    {
        $notebook = $this->notebooks->create($this->user, ['name' => 'Ordner/Test']);
        $this->pages->create($this->user, 'note', '../../etc/passwd', null, (int) $notebook['id']);

        $names = array_keys($this->exportEntries([(int) $notebook['id']]));

        foreach ($names as $name) {
            self::assertStringNotContainsString('..', $name);
            self::assertSame(1, substr_count($name, '/'), "Unerwartete Verschachtelung: {$name}");
        }
    }

    public function testGivesEqualTitlesDistinctFileNames(): void
    {
        $notebook = $this->notebooks->create($this->user, ['name' => 'Doppelt']);
        $this->pages->create($this->user, 'note', 'Gleicher Titel', null, (int) $notebook['id']);
        $this->pages->create($this->user, 'note', 'Gleicher Titel', null, (int) $notebook['id']);

        $names = array_keys($this->exportEntries([(int) $notebook['id']]));
        sort($names);

        self::assertSame(['Doppelt/Gleicher Titel-2.md', 'Doppelt/Gleicher Titel.md'], $names);
    }

    public function testExportsUnassignedPagesWhenRequested(): void
    {
        $this->pages->create($this->user, 'note', 'Ohne Buch', null);

        $entries = $this->exportEntries([], true);

        self::assertArrayHasKey('Nicht zugewiesen/Ohne Buch.md', $entries);
    }

    public function testTrashedPagesAreNotExported(): void
    {
        $notebook = $this->notebooks->create($this->user, ['name' => 'Rest']);
        $keep = $this->pages->create($this->user, 'note', 'Bleibt', null, (int) $notebook['id']);
        $gone = $this->pages->create($this->user, 'note', 'Gelöscht', null, (int) $notebook['id']);
        $this->pages->softDelete($this->user, (int) $gone['id']);

        $names = array_keys($this->exportEntries([(int) $notebook['id']]));

        self::assertSame(['Rest/Bleibt.md'], $names);
        self::assertNotSame('', (string) $keep['id']);
    }

    public function testSelectableListsNotebooksWithCounts(): void
    {
        $notebook = $this->notebooks->create($this->user, ['name' => 'Zählen']);
        $this->pages->create($this->user, 'note', 'Eins', null, (int) $notebook['id']);
        $this->pages->create($this->user, 'note', 'Zwei', null, (int) $notebook['id']);
        $this->pages->create($this->user, 'note', 'Frei', null);

        $selectable = $this->export->selectable($this->user);

        self::assertSame('Zählen', $selectable[0]['name']);
        self::assertSame(2, $selectable[0]['page_count']);
        self::assertNull($selectable[1]['id']);
        self::assertSame(1, $selectable[1]['page_count']);
    }

    public function testForeignNotebookIsRejected(): void
    {
        $other = $this->makeUser('b@example.com');
        $foreign = $this->notebooks->create($other, ['name' => 'Fremd']);

        $this->expectException(ValidationException::class);
        $this->export->export($this->user, [(int) $foreign['id']], false);
    }

    public function testEmptySelectionIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->export->export($this->user, [], false);
    }

    public function testSelectionWithoutPagesIsRejected(): void
    {
        $notebook = $this->notebooks->create($this->user, ['name' => 'Leer']);

        $this->expectException(ValidationException::class);
        $this->export->export($this->user, [(int) $notebook['id']], false);
    }

    // ---- Hilfsfunktionen -------------------------------------------------

    /**
     * @param array<int, int> $notebookIds
     *
     * @return array<string, string> Archivpfad → Inhalt
     */
    private function exportEntries(array $notebookIds, bool $unassigned = false): array
    {
        $archive = $this->export->export($this->user, $notebookIds, $unassigned);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive['path']) === true);

        $entries = [];
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $name = (string) $zip->getNameIndex($index);
            $entries[$name] = (string) $zip->getFromIndex($index);
        }
        $zip->close();

        return $entries;
    }

    private function attachImage(int $pageId, string $originalName): string
    {
        $token = bin2hex(random_bytes(32));
        $storageName = $this->storage->writeImage($pageId, 'PNG-BYTES', 'png');
        $this->imageRepository->create(
            $pageId,
            hash('sha256', $token),
            $storageName,
            $originalName,
            'image/png',
            10,
            100,
            50,
            $this->user->id,
        );

        return $token;
    }

    private function attachFile(int $pageId, string $originalName): void
    {
        $storageName = $this->storage->writeFile($pageId, 'PDF-BYTES');
        $this->fileRepository->create(
            $pageId,
            hash('sha256', bin2hex(random_bytes(32))),
            $storageName,
            $originalName,
            'application/pdf',
            9,
            $this->user->id,
        );
    }

    private function makeUser(string $email): User
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (google_sub, email, name, created_at) VALUES (:sub, :email, :name, :now)'
        );
        $statement->execute([
            'sub' => $email,
            'email' => $email,
            'name' => $email,
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->workspaces->createForUser($id);

        return new User($id, $email, $email, $email, null, true, false);
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
