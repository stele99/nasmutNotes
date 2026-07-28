<?php

declare(strict_types=1);

namespace Tests\Integration\Domain\Export;

use App\Domain\Export\MarkdownRenderer;
use App\Domain\Export\NotebookExportService;
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
use App\Repositories\CategoryRepository;
use App\Repositories\NoteAttachmentRepository;
use App\Repositories\NotebookRepository;
use App\Repositories\NoteContentRepository;
use App\Repositories\NoteVersionRepository;
use App\Repositories\PageAttachmentRepository;
use App\Repositories\PageRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\ShareRepository;
use App\Repositories\TaskRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\UploadStorage;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDatabaseTrait;

/**
 * Ein Export soll sich in dieselbe Anwendung zurückspielen lassen. Das ist der
 * schärfste Test für das erzeugte Markdown: Was der eigene Import nicht wieder
 * versteht, ist auch für andere Werkzeuge fragwürdig.
 */
final class ExportImportRoundTripTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PDO $pdo;
    private string $root;
    private WorkspaceRepository $workspaces;
    private PageService $pages;
    private NotebookService $notebooks;
    private NoteService $notes;
    private NoteContentRepository $contents;
    private NotebookExportService $export;
    private ZipImportService $import;
    private User $user;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/roundtrip-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/uploads', 0770, true);

        $this->pdo = $this->makeDatabase();
        $this->workspaces = new WorkspaceRepository($this->pdo);
        $notebookRepository = new NotebookRepository($this->pdo);
        $pageRepository = new PageRepository($this->pdo);
        $this->contents = new NoteContentRepository($this->pdo);
        $imageRepository = new NoteAttachmentRepository($this->pdo);
        $fileRepository = new PageAttachmentRepository($this->pdo);
        $settings = new SettingsRepository($this->pdo);
        $storage = new UploadStorage($this->root, 'uploads');
        $auditLog = new AuditLogRepository($this->pdo);

        $this->notebooks = new NotebookService($this->pdo, $notebookRepository, $this->workspaces);
        $this->pages = new PageService(
            $pageRepository,
            $this->workspaces,
            new ShareRepository($this->pdo),
            $this->notebooks,
        );
        $this->notes = new NoteService(
            $this->pdo,
            $this->pages,
            $pageRepository,
            $this->contents,
            new NoteVersionRepository($this->pdo),
            $imageRepository,
            new ProseMirrorValidator(),
        );

        $this->export = new NotebookExportService(
            $this->workspaces,
            $notebookRepository,
            $pageRepository,
            $this->contents,
            $imageRepository,
            $fileRepository,
            new CategoryRepository($this->pdo),
            new TaskRepository($this->pdo),
            $storage,
            new MarkdownRenderer(),
            $auditLog,
            $this->root . '/tmp',
        );

        $this->import = new ZipImportService(
            $this->pages,
            $this->notes,
            new AttachmentService($this->pages, $imageRepository, $storage, 10, $settings, 0, $fileRepository),
            new PageAttachmentService($this->pages, $fileRepository, $imageRepository, $storage, $settings),
            $pageRepository,
            $this->notebooks,
            new MarkdownConverter(),
            $auditLog,
        );

        $this->user = $this->makeUser('a@example.com');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testFormattedNoteSurvivesExportAndReimport(): void
    {
        $original = [
            'type' => 'doc',
            'content' => [
                ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [
                    ['type' => 'text', 'text' => 'Zwischenüberschrift'],
                ]],
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'text' => 'Normal, '],
                    ['type' => 'text', 'text' => 'fett', 'marks' => [['type' => 'bold']]],
                    ['type' => 'text', 'text' => ' und '],
                    ['type' => 'text', 'text' => 'kursiv', 'marks' => [['type' => 'italic']]],
                    ['type' => 'text', 'text' => '.'],
                ]],
                ['type' => 'bulletList', 'content' => [
                    $this->listItem('Erster Punkt'),
                    $this->listItem('Zweiter Punkt'),
                ]],
                ['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [
                    ['type' => 'text', 'text' => "echo 'hallo';"],
                ]],
            ],
        ];

        $reimported = $this->roundTrip('Formatiert', $original);

        self::assertSame($this->outline($original), $this->outline($reimported));
        self::assertStringContainsString('Zwischenüberschrift', $this->plainText($reimported));
        self::assertStringContainsString('Erster Punkt', $this->plainText($reimported));
        self::assertStringContainsString("echo 'hallo';", $this->plainText($reimported));
        self::assertSame(
            ['bold', 'italic'],
            $this->marksIn($reimported),
            'Fett und kursiv müssen den Weg über Markdown überstehen.',
        );
    }

    /** Sonderzeichen dürfen beim Rückweg nicht als Markdown-Syntax gelesen werden. */
    public function testTextWithMarkdownSyntaxSurvivesUnchanged(): void
    {
        $tricky = '5 * 3 und [Klammern] und _kein_ Kursiv';
        $original = [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $tricky]]],
            ],
        ];

        $reimported = $this->roundTrip('Sonderzeichen', $original);

        self::assertSame($tricky, trim($this->plainText($reimported)));
    }

    public function testTaskListSurvivesRoundTrip(): void
    {
        $original = [
            'type' => 'doc',
            'content' => [
                ['type' => 'taskList', 'content' => [
                    ['type' => 'taskItem', 'attrs' => ['checked' => true], 'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'erledigt']]],
                    ]],
                    ['type' => 'taskItem', 'attrs' => ['checked' => false], 'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'offen']]],
                    ]],
                ]],
            ],
        ];

        $reimported = $this->roundTrip('Checkliste', $original);

        $checked = [];
        foreach ($reimported['content'][0]['content'] ?? [] as $item) {
            $checked[] = ($item['attrs']['checked'] ?? false) === true;
        }

        self::assertSame([true, false], $checked);
    }

    public function testTableSurvivesRoundTrip(): void
    {
        $original = [
            'type' => 'doc',
            'content' => [
                ['type' => 'table', 'content' => [
                    ['type' => 'tableRow', 'content' => [
                        $this->cell('tableHeader', 'Spalte A'),
                        $this->cell('tableHeader', 'Spalte B'),
                    ]],
                    ['type' => 'tableRow', 'content' => [
                        $this->cell('tableCell', 'eins'),
                        $this->cell('tableCell', 'zwei'),
                    ]],
                ]],
            ],
        ];

        $reimported = $this->roundTrip('Tabelle', $original);
        $text = $this->plainText($reimported);

        self::assertSame('table', $reimported['content'][0]['type'] ?? null);
        foreach (['Spalte A', 'Spalte B', 'eins', 'zwei'] as $cell) {
            self::assertStringContainsString($cell, $text);
        }
    }

    // ---- Hilfsfunktionen -------------------------------------------------

    /**
     * Exportiert eine Notiz, importiert das Archiv erneut und liefert das
     * Dokument der neu entstandenen Seite.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function roundTrip(string $title, array $document): array
    {
        $notebook = $this->notebooks->create($this->user, ['name' => 'Quelle']);
        $page = $this->pages->create($this->user, 'note', $title, null, (int) $notebook['id']);
        $this->notes->save($this->user, (int) $page['id'], $document, 1);

        $archive = $this->export->export($this->user, [(int) $notebook['id']], false);

        $report = $this->import->importFromPath($this->user, $archive['path'], 'test');
        self::assertSame(1, $report->pages, 'Der Import muss genau eine Seite anlegen.');

        $statement = $this->pdo->query(
            'SELECT id FROM pages WHERE type = "note" ORDER BY id DESC LIMIT 1'
        );
        self::assertNotFalse($statement);
        $imported = $statement->fetchColumn();
        self::assertNotFalse($imported);

        $row = $this->contents->find((int) $imported);
        self::assertNotNull($row);
        $decoded = json_decode((string) $row['content'], true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * Blockfolge des Dokuments - der Vergleich soll an der Struktur hängen,
     * nicht an Kleinigkeiten der Textknoten.
     *
     * @param array<string, mixed> $document
     *
     * @return array<int, string>
     */
    private function outline(array $document): array
    {
        return array_map(
            static fn (array $node): string => (string) ($node['type'] ?? ''),
            array_values(array_filter($document['content'] ?? [], 'is_array')),
        );
    }

    /** @param array<string, mixed> $node */
    private function plainText(array $node): string
    {
        if (($node['type'] ?? '') === 'text') {
            return (string) ($node['text'] ?? '');
        }

        $out = '';
        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $out .= $this->plainText($child) . ' ';
            }
        }

        return trim($out);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, true>  $found
     *
     * @return array<int, string>
     */
    private function marksIn(array $node, array &$found = []): array
    {
        foreach ($node['marks'] ?? [] as $mark) {
            if (is_array($mark) && is_string($mark['type'] ?? null)) {
                $found[$mark['type']] = true;
            }
        }
        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $this->marksIn($child, $found);
            }
        }

        $types = array_keys($found);
        sort($types);

        return $types;
    }

    /** @return array<string, mixed> */
    private function listItem(string $text): array
    {
        return ['type' => 'listItem', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
        ]];
    }

    /** @return array<string, mixed> */
    private function cell(string $type, string $text): array
    {
        return ['type' => $type, 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
        ]];
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
