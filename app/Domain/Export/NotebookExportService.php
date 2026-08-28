<?php

declare(strict_types=1);

namespace App\Domain\Export;

use App\Domain\Notes\ImageAnnotationSvgRenderer;
use App\Domain\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\LogRepository;
use App\Repositories\NoteAttachmentRepository;
use App\Repositories\NotebookRepository;
use App\Repositories\NoteContentRepository;
use App\Repositories\PageAttachmentRepository;
use App\Repositories\PageRepository;
use App\Repositories\TaskRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\UploadStorage;
use App\Support\ValidationException;
use ZipArchive;

/**
 * Export ausgewählter Notizbücher als ZIP (FR-EXP-03).
 *
 * Aufbau je Notizbuch ein Ordner, darin je Seite eine Markdown-Datei mit
 * Frontmatter und ein Unterordner `files/` mit allen Bildern und Anhängen:
 *
 * ```
 * Notizbuch/
 *   Meine Notiz.md
 *   Meine Aufgaben.md
 *   files/
 *     screenshot.png
 * ```
 *
 * Die Bildverweise zeigen relativ auf `files/…`, genau die Form, die der
 * Import wieder auflöst - ein Export lässt sich damit zurückspielen.
 */
final class NotebookExportService
{
    /** Zusammengebaute Archive sind jederzeit neu erzeugbar und verfallen. */
    private const TMP_TTL_SECONDS = 3600;

    private const IMAGE_EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly NotebookRepository $notebooks,
        private readonly PageRepository $pages,
        private readonly NoteContentRepository $contents,
        private readonly NoteAttachmentRepository $images,
        private readonly PageAttachmentRepository $files,
        private readonly CategoryRepository $categories,
        private readonly TaskRepository $tasks,
        private readonly LogRepository $log,
        private readonly UploadStorage $storage,
        private readonly MarkdownRenderer $markdown,
        private readonly AuditLogRepository $auditLog,
        private readonly string $tmpPath,
        private readonly ImageAnnotationSvgRenderer $overlay = new ImageAnnotationSvgRenderer(),
    ) {
    }

    /**
     * Auswahlliste für die Oberfläche: alle Notizbücher plus die Seiten ohne
     * Notizbuch. Der Papierkorb bleibt außen vor.
     *
     * @return array<int, array{id: ?int, name: string, page_count: int}>
     */
    public function selectable(User $user): array
    {
        $workspaceId = $this->workspaceId($user);
        $result = [];

        foreach ($this->notebooks->listForWorkspace($workspaceId) as $notebook) {
            $id = (int) $notebook['id'];
            $result[] = [
                'id' => $id,
                'name' => (string) $notebook['name'],
                'page_count' => count($this->pagesOf($workspaceId, $id, false)),
            ];
        }

        $unassigned = $this->pagesOf($workspaceId, null, true);
        if ($unassigned !== []) {
            $result[] = ['id' => null, 'name' => 'Nicht zugewiesen', 'page_count' => count($unassigned)];
        }

        return $result;
    }

    /**
     * @param array<int, int> $notebookIds
     *
     * @return array{path: string, filename: string, bytes: int, pages: int, files: int}
     */
    public function export(
        User $user,
        array $notebookIds,
        bool $includeUnassigned,
        ?string $ipHash = null,
    ): array {
        $workspaceId = $this->workspaceId($user);

        $selections = [];
        foreach (array_unique(array_map('intval', $notebookIds)) as $notebookId) {
            $notebook = $this->notebooks->findByIdForWorkspace($notebookId, $workspaceId);
            if ($notebook === null) {
                throw new ValidationException('Ein ausgewähltes Notizbuch existiert nicht.');
            }
            $selections[] = ['id' => $notebookId, 'name' => (string) $notebook['name']];
        }
        if ($includeUnassigned) {
            $selections[] = ['id' => null, 'name' => 'Nicht zugewiesen'];
        }

        if ($selections === []) {
            throw new ValidationException('Bitte mindestens ein Notizbuch auswählen.');
        }

        $this->sweepTmp();
        if (!is_dir($this->tmpPath) && !mkdir($this->tmpPath, 0750, true) && !is_dir($this->tmpPath)) {
            throw new \RuntimeException('Das temporäre Verzeichnis konnte nicht angelegt werden.');
        }

        $path = $this->tmpPath . '/export-' . bin2hex(random_bytes(8)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new \RuntimeException('Das Archiv konnte nicht angelegt werden.');
        }

        $pageCount = 0;
        $fileCount = 0;
        $usedFolders = [];

        foreach ($selections as $selection) {
            $folder = $this->uniqueName($this->safeName($selection['name'], 'Notizbuch'), $usedFolders);
            $pages = $this->pagesOf($workspaceId, $selection['id'], $selection['id'] === null);
            if ($pages === []) {
                continue;
            }

            $usedNames = [];
            $usedFiles = [];
            foreach ($pages as $page) {
                $written = $this->writePage(
                    $zip,
                    $folder,
                    $page,
                    (string) $selection['name'],
                    $usedNames,
                    $usedFiles,
                );
                ++$pageCount;
                $fileCount += $written;
            }
        }

        if ($pageCount === 0) {
            $zip->close();
            @unlink($path);

            throw new ValidationException('Die Auswahl enthält keine Seiten.');
        }

        if (!$zip->close()) {
            @unlink($path);

            throw new \RuntimeException('Das Archiv konnte nicht geschrieben werden.');
        }

        $this->auditLog->log($user->id, 'notes_exported', null, null, $ipHash, [
            'notebooks' => count($selections),
            'pages' => $pageCount,
            'files' => $fileCount,
        ]);

        return [
            'path' => $path,
            'filename' => 'nasmutnotes-export-' . gmdate('Y-m-d') . '.zip',
            'bytes' => (int) filesize($path),
            'pages' => $pageCount,
            'files' => $fileCount,
        ];
    }

    /**
     * @param array<string, mixed>  $page
     * @param array<string, true>   $usedNames
     * @param array<string, true>   $usedFiles
     *
     * @return int Zahl der mitgeschriebenen Dateien
     */
    private function writePage(
        ZipArchive $zip,
        string $folder,
        array $page,
        string $notebookName,
        array &$usedNames,
        array &$usedFiles,
    ): int {
        $pageId = (int) $page['id'];
        $title = (string) ($page['title'] ?? 'Ohne Titel');
        if (($page['type'] ?? null) === 'note' && (bool) ($page['is_encrypted'] ?? false)) {
            $name = $this->uniqueName(
                $this->safeName($title, 'Verschlüsselte Notiz') . '.encrypted-note.json',
                $usedNames,
            );
            $this->writeEncryptedNote($zip, $folder . '/' . $name, $page);

            return 0;
        }

        $name = $this->uniqueName($this->safeName($title, 'Ohne Titel'), $usedNames);

        $written = 0;
        // Erst die Dateien einsammeln: Die Verweise im Markdown brauchen die
        // endgültigen Namen im Archiv.
        $imageTargets = [];
        foreach ($this->images->listForPage($pageId) as $image) {
            $source = $this->storage->path((string) $image['storage_name']);
            if ($source === null) {
                continue;
            }
            $fileName = $this->uniqueName($this->imageName($image), $usedFiles);
            $zip->addFile($source, $folder . '/files/' . $fileName);
            $zip->setCompressionName($folder . '/files/' . $fileName, ZipArchive::CM_STORE);
            $imageTargets[(string) $image['token_hash']] = 'files/' . $fileName;
            ++$written;
        }

        $attachments = [];
        foreach ($this->files->listForPage($pageId) as $file) {
            $source = $this->storage->path((string) $file['storage_name']);
            if ($source === null) {
                continue;
            }
            $fileName = $this->uniqueName(
                $this->safeName((string) $file['original_name'], 'anhang'),
                $usedFiles,
            );
            $zip->addFile($source, $folder . '/files/' . $fileName);
            $zip->setCompressionName($folder . '/files/' . $fileName, ZipArchive::CM_STORE);
            $attachments[] = ['name' => (string) $file['original_name'], 'target' => 'files/' . $fileName];
            ++$written;
        }

        $body = match ($page['type'] ?? 'note') {
            'task' => $this->taskMarkdown($pageId),
            'log' => $this->logMarkdown($pageId),
            default => $this->noteMarkdown($pageId, $imageTargets),
        };

        $markdown = $this->frontMatter($page, $notebookName)
            . "\n# " . $this->escapeHeading($title) . "\n\n"
            . $body
            . $this->attachmentSection($attachments);

        $zip->addFromString($folder . '/' . $name . '.md', $markdown);

        if (($page['type'] ?? 'note') === 'note') {
            foreach ($this->annotationSidecars($pageId, $imageTargets) as $target => $svg) {
                $zip->addFromString($folder . '/' . $target, $svg);
                ++$written;
            }
        }

        return $written;
    }

    /**
     * Ein Overlay je annotiertem Bild, benannt nach der Bilddatei:
     * `files/bild-ab12cd34ef56.png` → `files/bild-ab12cd34ef56.annotations.svg`.
     *
     * @param array<string, string> $imageTargets Token-Hash → Pfad im Archiv
     *
     * @return array<string, string> Pfad im Archiv → SVG-Inhalt
     */
    private function annotationSidecars(int $pageId, array $imageTargets): array
    {
        $row = $this->contents->find($pageId);
        if ($row === null) {
            return [];
        }
        $document = json_decode((string) $row['content'], true);
        if (!is_array($document)) {
            return [];
        }

        $sidecars = [];
        $this->collectSidecars($document, $imageTargets, $sidecars);

        return $sidecars;
    }

    /**
     * @param array<string, mixed>  $node
     * @param array<string, string> $imageTargets
     * @param array<string, string> $sidecars
     */
    private function collectSidecars(array $node, array $imageTargets, array &$sidecars): void
    {
        if (($node['type'] ?? null) === 'image') {
            $src = (string) ($node['attrs']['src'] ?? '');
            $svg = $this->overlay->render($node['attrs']['annotations'] ?? null);
            if ($svg !== '' && preg_match('#^/api/attachments/([a-f0-9]{64})$#', $src, $matches) === 1) {
                $target = $imageTargets[hash('sha256', $matches[1])] ?? null;
                if ($target !== null) {
                    $name = preg_replace('/\.[^.]+$/', '', $target) . '.annotations.svg';
                    // Der Namensraum fehlt im eingebetteten SVG (es steht in
                    // HTML) und wird hier nachgetragen: Eine eigenständige
                    // .svg-Datei öffnet sonst kein Programm.
                    $sidecars[(string) $name] = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                        . str_replace('<svg ', '<svg xmlns="http://www.w3.org/2000/svg" ', $svg) . "\n";
                }
            }
        }

        foreach ((array) ($node['content'] ?? []) as $child) {
            if (is_array($child)) {
                $this->collectSidecars($child, $imageTargets, $sidecars);
            }
        }
    }

    /** @param array<string, mixed> $page */
    private function writeEncryptedNote(ZipArchive $zip, string $target, array $page): void
    {
        $row = $this->contents->find((int) $page['id']);
        $envelope = $row !== null ? json_decode((string) $row['content'], true) : null;
        if (!is_array($envelope)) {
            throw new ValidationException('Eine verschlüsselte Notiz enthält keinen gültigen Umschlag.');
        }

        $export = [
            'format' => 'nasmutNotes-encrypted-note',
            'version' => 1,
            'title' => (string) ($page['title'] ?? 'Ohne Titel'),
            'original_page_id' => (string) $page['id'],
            'created_at' => $page['created_at'] ?? null,
            'updated_at' => $page['updated_at'] ?? null,
            'envelope' => $envelope,
        ];
        $json = json_encode(
            $export,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $zip->addFromString($target, $json . "\n");
    }

    /** @param array<string, string> $imageTargets Token-Hash → Pfad im Archiv */
    private function noteMarkdown(int $pageId, array $imageTargets): string
    {
        $row = $this->contents->find($pageId);
        if ($row === null) {
            return '';
        }

        $document = json_decode((string) $row['content'], true);
        if (!is_array($document)) {
            return '';
        }

        return $this->markdown->render(
            $document,
            // Das Dokument kennt nur das Klartext-Token, die Datenbank nur
            // dessen Hash - der Abgleich läuft deshalb über den Hash.
            static fn (string $token): ?string => $imageTargets[hash('sha256', $token)] ?? null,
        );
    }

    /**
     * Logbuch als Markdown-Tabelle: erste Spalte der Zeitpunkt, dann die
     * eigenen Spalten in ihrer Reihenfolge (FR-LOG-10).
     */
    private function logMarkdown(int $pageId): string
    {
        $columns = $this->log->columnsForPage($pageId);
        $entries = $this->log->entriesForPage($pageId, null, false);
        if ($entries === []) {
            return "_Keine Einträge._\n";
        }

        $header = array_merge(['Zeitpunkt'], array_map(
            fn (array $column): string => $this->escapeCell((string) $column['name']),
            $columns,
        ));

        $lines = [
            '| ' . implode(' | ', $header) . ' |',
            '| ' . implode(' | ', array_fill(0, count($header), '---')) . ' |',
        ];

        foreach ($entries as $entry) {
            $cells = [$this->escapeCell(str_replace('T', ' ', substr((string) $entry['occurred_at'], 0, 16)))];
            foreach ($columns as $column) {
                $value = $entry['values'][(int) $column['id']] ?? null;
                $cells[] = $this->escapeCell($this->logCell($value, (string) $column['type']));
            }
            $lines[] = '| ' . implode(' | ', $cells) . ' |';
        }

        return implode("\n", $lines) . "\n";
    }

    /** @param array<string, mixed>|null $value */
    private function logCell(?array $value, string $type): string
    {
        if ($value === null) {
            return '';
        }

        if (in_array($type, ['hours', 'number', 'money'], true)) {
            if ($value['value_number'] === null) {
                return '';
            }
            $number = number_format((float) $value['value_number'], $type === 'number' ? 2 : 2, ',', '.');

            return match ($type) {
                'hours' => $number . ' h',
                'money' => $number . ' €',
                default => $number,
            };
        }

        $text = $value['value_text'] !== null ? (string) $value['value_text'] : '';
        if ($type === 'location' && $value['value_lat'] !== null) {
            $coordinates = round((float) $value['value_lat'], 5) . ', ' . round((float) $value['value_lon'], 5);

            return $text !== '' ? "{$text} ({$coordinates})" : $coordinates;
        }

        return $text;
    }

    /** In einer Markdown-Tabelle trennt der senkrechte Strich die Zellen. */
    private function escapeCell(string $value): string
    {
        return trim(str_replace(['|', "\r\n", "\n", "\r"], ['\\|', ' ', ' ', ' '], $value));
    }

    /**
     * Task-Seite als gewöhnliche Markdown-Checkliste: je Kategorie eine
     * Überschrift, darunter die Aufgaben (FR-EXP-02 in Markdown-Form).
     */
    private function taskMarkdown(int $pageId): string
    {
        $categories = $this->categories->listForPage($pageId);
        if ($categories === []) {
            return "_Keine Aufgaben._\n";
        }

        $tasksByCategory = [];
        foreach ($this->tasks->listForPage($pageId) as $task) {
            $tasksByCategory[(int) $task['category_id']][] = $task;
        }

        $sections = [];
        foreach ($categories as $category) {
            $lines = ['## ' . $this->escapeHeading((string) $category['name'])];
            $tasks = $tasksByCategory[(int) $category['id']] ?? [];

            if ($tasks === []) {
                $lines[] = '';
                $lines[] = '_Keine Aufgaben._';
                $sections[] = implode("\n", $lines);

                continue;
            }

            $lines[] = '';
            foreach ($tasks as $task) {
                $done = ((int) ($task['is_done'] ?? 0)) === 1;
                $lines[] = ($done ? '- [x] ' : '- [ ] ') . $this->inlineText((string) $task['title']);

                foreach ($this->taskDetails($task) as $detail) {
                    $lines[] = '  ' . $detail;
                }
            }
            $sections[] = implode("\n", $lines);
        }

        return implode("\n\n", $sections) . "\n";
    }

    /**
     * @param array<string, mixed> $task
     *
     * @return array<int, string>
     */
    private function taskDetails(array $task): array
    {
        $details = [];

        $description = trim((string) ($task['description'] ?? ''));
        if ($description !== '') {
            foreach (explode("\n", $description) as $line) {
                $details[] = $this->inlineText($line);
            }
        }

        $meta = [];
        $responsible = trim((string) ($task['responsible'] ?? ''));
        if ($responsible !== '') {
            $meta[] = 'Verantwortlich: ' . $this->inlineText($responsible);
        }
        $due = trim((string) ($task['due_date'] ?? ''));
        if ($due !== '') {
            $meta[] = 'Fällig: ' . $this->inlineText($due);
        }
        $priority = trim((string) ($task['priority'] ?? ''));
        if ($priority !== '') {
            $meta[] = 'Priorität: ' . $this->inlineText($priority);
        }
        if ($meta !== []) {
            $details[] = implode(' · ', $meta);
        }

        $link = trim((string) ($task['link'] ?? ''));
        if ($link !== '') {
            $details[] = '<' . $link . '>';
        }

        return $details;
    }

    /** @param array<int, array{name: string, target: string}> $attachments */
    private function attachmentSection(array $attachments): string
    {
        if ($attachments === []) {
            return '';
        }

        $lines = ["\n## Anhänge\n"];
        foreach ($attachments as $attachment) {
            $lines[] = '- [' . $this->inlineText($attachment['name']) . '](' . $attachment['target'] . ')';
        }

        return implode("\n", $lines) . "\n";
    }

    /** @param array<string, mixed> $page */
    private function frontMatter(array $page, string $notebookName): string
    {
        $lines = ['---'];
        $lines[] = 'title: ' . $this->yaml((string) ($page['title'] ?? 'Ohne Titel'));
        $lines[] = 'type: ' . match ($page['type'] ?? 'note') {
            'task' => 'task',
            'log' => 'log',
            default => 'note',
        };
        $lines[] = 'notebook: ' . $this->yaml($notebookName);
        if (($page['created_at'] ?? null) !== null) {
            $lines[] = 'created: ' . $this->yaml((string) $page['created_at']);
        }
        if (($page['updated_at'] ?? null) !== null) {
            $lines[] = 'updated: ' . $this->yaml((string) $page['updated_at']);
        }
        if (((int) ($page['is_favorite'] ?? 0)) === 1) {
            $lines[] = 'favorite: true';
        }
        $lines[] = '---';

        return implode("\n", $lines) . "\n";
    }

    private function yaml(string $value): string
    {
        return '"' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', ' '], $value) . '"';
    }

    /** Titel stehen in Überschriften und dürfen dort keine Zeile umbrechen. */
    private function escapeHeading(string $value): string
    {
        return $this->inlineText($value);
    }

    private function inlineText(string $value): string
    {
        $single = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return str_replace(['[', ']'], ['\\[', '\\]'], $single);
    }

    /**
     * @param array<string, mixed> $image
     */
    private function imageName(array $image): string
    {
        $original = trim((string) ($image['original_name'] ?? ''));
        if ($original !== '') {
            return $this->safeName($original, 'bild');
        }

        $extension = self::IMAGE_EXTENSIONS[(string) $image['mime_type']] ?? 'png';

        return 'bild-' . substr((string) $image['token_hash'], 0, 12) . '.' . $extension;
    }

    /**
     * Dateinamen aus Nutzereingaben: Pfadtrenner und Steuerzeichen müssen raus,
     * sonst entstünde beim Entpacken ein Verzeichnis oder Schlimmeres.
     */
    private function safeName(string $value, string $fallback): string
    {
        $name = str_replace(["\0", '/', '\\'], ' ', $value);
        $name = preg_replace('/[\x00-\x1F\x7F<>:"|?*]/u', '', $name) ?? $name;
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        // Punktfolgen einebnen und Rand-Punkte entfernen: Aus "../../etc" darf
        // kein Name mit ".." werden, aus ".git" keine versteckte Datei.
        while (str_contains($name, '..')) {
            $name = str_replace('..', '.', $name);
        }
        $name = trim($name, " \t.");

        if ($name === '') {
            return $fallback;
        }

        // Genug Luft für den Zähler bei Namensgleichheit und die Endung.
        return mb_substr($name, 0, 120);
    }

    /**
     * Vergibt einen im Ordner eindeutigen Namen. Gleichnamige Seiten bekommen
     * einen Zähler angehängt.
     *
     * @param array<string, true> $used Bereits vergebene Namen (kleingeschrieben)
     */
    private function uniqueName(string $name, array &$used): string
    {
        $extension = '';
        $base = $name;
        $dot = strrpos($name, '.');
        if ($dot !== false && $dot > 0) {
            $extension = substr($name, $dot);
            $base = substr($name, 0, $dot);
        }

        $candidate = $base . $extension;
        $counter = 2;
        while (isset($used[mb_strtolower($candidate)])) {
            $candidate = $base . '-' . $counter . $extension;
            ++$counter;
        }
        $used[mb_strtolower($candidate)] = true;

        return $candidate;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pagesOf(int $workspaceId, ?int $notebookId, bool $unassigned): array
    {
        return $this->pages->listForWorkspace(
            $workspaceId,
            'updated',
            null,
            false,
            $notebookId,
            $unassigned,
        );
    }

    private function workspaceId(User $user): int
    {
        $workspaceId = $this->workspaces->findByUserId($user->id);
        if ($workspaceId === null) {
            throw new ValidationException('Zu diesem Konto gehört kein Workspace.');
        }

        return $workspaceId;
    }

    private function sweepTmp(): void
    {
        foreach (glob($this->tmpPath . '/*.zip') ?: [] as $file) {
            if (time() - (int) @filemtime($file) > self::TMP_TTL_SECONDS) {
                @unlink($file);
            }
        }
    }
}
