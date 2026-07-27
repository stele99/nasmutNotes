<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\NotebookService;
use App\Domain\Notes\AttachmentService;
use App\Domain\Notes\NoteService;
use App\Domain\Notes\PageAttachmentService;
use App\Domain\PageService;
use App\Domain\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\PageRepository;
use App\Support\ValidationException;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use ZipArchive;

/**
 * Import eines Markdown-Archivs aus einem anderen Notizwerkzeug (FR-IMP-19..24).
 *
 * Erwartet wird ein ZIP mit `.md`-Dateien und einem Ordner mit den zugehörigen
 * Dateien (UpNote legt ihn als `Files/` neben die Notizen). Jede Notiz wird zu
 * einer Seite; eingebettete Bilder werden zu Bildanhängen, alle übrigen Dateien
 * zu Dateianhängen der Seite.
 */
final class ZipImportService
{
    /** Bilder, die der Editor einbetten kann; alles andere wird Dateianhang. */
    private const IMAGE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    private const MAX_ENTRIES = 20_000;
    private const MAX_TOTAL_BYTES = 2_000_000_000;
    private const MAX_NOTE_BYTES = 2_000_000;
    private const MAX_TITLE_LENGTH = 200;

    public function __construct(
        private readonly PageService $pages,
        private readonly NoteService $notes,
        private readonly AttachmentService $images,
        private readonly PageAttachmentService $files,
        private readonly PageRepository $pageRepository,
        private readonly NotebookService $notebooks,
        private readonly MarkdownConverter $converter,
        private readonly AuditLogRepository $auditLog,
        private readonly int $maxArchiveMb = 500,
    ) {
    }

    public function maxArchiveMb(): int
    {
        return max(1, $this->maxArchiveMb);
    }

    /**
     * Kleinste tatsächlich wirksame Obergrenze für den Upload. PHP weist zu
     * große Anfragen ab, bevor die Anwendung sie sieht — der Request kommt dann
     * ohne Datei und ohne Fehlerhinweis an. Damit die Oberfläche vorher warnen
     * kann, zählt hier auch die PHP-Konfiguration.
     */
    public function maxUploadBytes(): int
    {
        $limits = [$this->maxArchiveMb() * 1024 * 1024];
        foreach (['upload_max_filesize', 'post_max_size'] as $directive) {
            $bytes = self::iniBytes((string) ini_get($directive));
            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }

        return min($limits);
    }

    public function maxArchiveBytes(): int
    {
        return $this->maxArchiveMb() * 1024 * 1024;
    }

    /**
     * Empfohlene Größe eines Upload-Teils: klein genug, dass PHP die Anfrage
     * samt Multipart-Rahmen annimmt, groß genug für wenige Anfragen.
     */
    public function chunkSize(): int
    {
        $usable = (int) ($this->maxUploadBytes() * 0.8);

        return max(128 * 1024, min(4 * 1024 * 1024, $usable));
    }

    /** @return array{upload_max_filesize: string, post_max_size: string} */
    public function phpUploadLimits(): array
    {
        return [
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'post_max_size' => (string) ini_get('post_max_size'),
        ];
    }

    /** Wandelt die PHP-Kurzschreibweise („2M", „1G") in Bytes; 0 heißt unbegrenzt. */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1' || $value === '0') {
            return 0;
        }

        $number = (int) $value;
        $unit = strtolower(substr($value, -1));

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    public function import(User $user, UploadedFileInterface $upload, string $ipHash): ImportReport
    {
        $archivePath = $this->toTemporaryFile($upload);

        try {
            return $this->importFromPath($user, $archivePath, $ipHash);
        } finally {
            @unlink($archivePath);
        }
    }

    /**
     * Import eines Archivs, das bereits vollständig auf dem Server liegt — etwa
     * aus einem in Teilen übertragenen Upload (FR-IMP-25). Die Datei bleibt
     * liegen; ihr Lebenszyklus gehört dem Aufrufer.
     */
    public function importFromPath(User $user, string $archivePath, string $ipHash): ImportReport
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new ValidationException('Die Datei konnte nicht als ZIP-Archiv gelesen werden.');
        }

        try {
            return $this->importArchive($user, $zip, $ipHash);
        } finally {
            $zip->close();
        }
    }

    private function importArchive(User $user, ZipArchive $zip, string $ipHash): ImportReport
    {
        $entries = $this->indexEntries($zip);
        $noteEntries = array_values(array_filter(
            array_keys($entries),
            static fn (string $name): bool => str_ends_with(strtolower($name), '.md'),
        ));
        sort($noteEntries);

        $notebookNames = $this->upNoteNotebookNames($entries, $noteEntries);

        if ($noteEntries === []) {
            throw new ValidationException('Das Archiv enthält keine Markdown-Dateien (.md).');
        }

        $report = new ImportReport();
        /** @var array<string, true> $usedEntries */
        $usedEntries = [];

        foreach ($noteEntries as $noteEntry) {
            if ($entries[$noteEntry] > self::MAX_NOTE_BYTES) {
                $report->skip(basename($noteEntry), 'Die Notiz ist größer als 2 MB.');
                continue;
            }

            $markdown = $zip->getFromName($noteEntry);
            if ($markdown === false) {
                $report->fail(basename($noteEntry), 'Die Datei konnte nicht aus dem Archiv gelesen werden.');
                continue;
            }

            $this->importNote(
                $user,
                $zip,
                $entries,
                $noteEntry,
                $markdown,
                $notebookNames[$noteEntry] ?? null,
                $report,
                $usedEntries,
            );
        }

        $report->unusedFiles = $this->countUnusedFiles($entries, $usedEntries);

        $this->auditLog->log($user->id, 'notes_imported', null, null, $ipHash, [
            'pages' => $report->pages,
            'images' => $report->images,
            'files' => $report->files,
            'skipped' => $report->skippedCount,
            'failed' => $report->failedCount,
        ]);

        return $report;
    }

    /**
     * @param array<string, int> $entries
     * @param array<string, true> $usedEntries
     */
    private function importNote(
        User $user,
        ZipArchive $zip,
        array $entries,
        string $noteEntry,
        string $markdown,
        ?string $notebookName,
        ImportReport $report,
        array &$usedEntries,
    ): void {
        $split = $this->converter->splitFrontMatter($markdown);
        $body = $split['body'];
        $title = $this->titleFor($noteEntry, $body);
        $report->deadLinks += $this->converter->countDeadResourceLinks($body);

        $pageId = null;
        try {
            $page = $this->pages->create($user, 'note', $title, null);
            $pageId = (int) $page['id'];

            $document = $this->converter->toDocument(
                $this->stripLeadingTitleHeading($body, $title),
                $this->assetResolver($user, $zip, $entries, $noteEntry, $pageId, $report, $usedEntries),
            );

            $this->notes->save($user, $pageId, $document, 1);
            $this->applyTimestamps($pageId, $split['meta']);
            if ($notebookName !== null) {
                $notebook = $this->notebooks->findOrCreate($user, $notebookName);
                $this->pages->update($user, $pageId, ['notebook_id' => (int) $notebook['id']]);
            }
            ++$report->pages;
        } catch (\Throwable $e) {
            // Eine halb angelegte Seite wäre schlimmer als gar keine: Sie stünde
            // ohne Inhalt in der Liste. Deshalb zurückrollen und melden.
            if ($pageId !== null) {
                $this->discardPage($user, $pageId);
            }
            $report->fail(basename($noteEntry), $e->getMessage());
        }
    }

    /**
     * Maps UpNote's notebook link entries to their note entries. Current
     * exports put notes directly beside `notebooks/`; older exports use a
     * sibling `all notes/` directory. A link is trusted only when it resolves
     * to exactly one Markdown file in either location.
     *
     * @param array<string, int> $entries
     * @param list<string> $noteEntries
     * @return array<string, string>
     */
    private function upNoteNotebookNames(array $entries, array $noteEntries): array
    {
        /** @var array<string, list<string>> $notesByDirectoryAndBasename */
        $notesByDirectoryAndBasename = [];
        foreach ($noteEntries as $noteEntry) {
            $directory = dirname($noteEntry);
            $notesByDirectoryAndBasename[$directory . "\0" . basename($noteEntry)][] = $noteEntry;
        }

        $notebookNames = [];
        foreach (array_keys($entries) as $entry) {
            if (preg_match('#^(.+)/notebooks/([^/]+)/([^/]+\.md)\.lnk$#i', $entry, $match) !== 1) {
                continue;
            }

            $notebookName = trim($match[2]);
            $matches = array_merge(
                $notesByDirectoryAndBasename[$match[1] . "\0" . $match[3]] ?? [],
                $notesByDirectoryAndBasename[$match[1] . '/all notes' . "\0" . $match[3]] ?? [],
            );
            if ($notebookName === '' || mb_strlen($notebookName) > 100 || count($matches) !== 1) {
                continue;
            }

            $notebookNames[$matches[0]] ??= $notebookName;
        }

        return $notebookNames;
    }

    /**
     * Liefert die Auflösung eines lokalen Verweises: Bilder werden zu
     * Bildanhängen, alles andere zu Dateianhängen der Seite.
     *
     * @param array<string, int> $entries
     * @param array<string, true> $usedEntries
     */
    private function assetResolver(
        User $user,
        ZipArchive $zip,
        array $entries,
        string $noteEntry,
        int $pageId,
        ImportReport $report,
        array &$usedEntries,
    ): callable {
        /** @var array<string, array<string, mixed>|null> $resolved Mehrfach genutzte Verweise nur einmal hochladen. */
        $resolved = [];

        return function (string $target, string $label, bool $asImage) use (
            $user,
            $zip,
            $entries,
            $noteEntry,
            $pageId,
            $report,
            &$usedEntries,
            &$resolved,
        ): ?array {
            $entry = $this->resolveEntry($entries, $noteEntry, $target);
            if ($entry === null) {
                return null;
            }

            // Querverweis auf eine andere Notiz: Die Zielseite entsteht aus
            // demselben Archiv, ein Dateianhang wäre hier nur Ballast.
            if (str_ends_with(strtolower($entry), '.md')) {
                return null;
            }

            $cacheKey = $entry . ($asImage ? '#image' : '#file');
            if (array_key_exists($cacheKey, $resolved)) {
                return $resolved[$cacheKey];
            }

            $bytes = $zip->getFromName($entry);
            if ($bytes === false || $bytes === '') {
                $report->skip(basename($entry), 'Die Datei konnte nicht aus dem Archiv gelesen werden.');

                return $resolved[$cacheKey] = null;
            }

            $usedEntries[$entry] = true;
            $fileName = basename($entry);
            $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

            if ($asImage && is_string($mimeType) && in_array($mimeType, self::IMAGE_MIME_TYPES, true)) {
                try {
                    $uploaded = $this->images->upload($user, $pageId, $this->uploadedFile($bytes, $fileName));
                    ++$report->images;

                    return $resolved[$cacheKey] = [
                        'kind' => 'image',
                        'src' => $uploaded['src'],
                        'width' => $uploaded['width'],
                        'height' => $uploaded['height'],
                    ];
                } catch (\Throwable $e) {
                    // Bild abgelehnt (z. B. Kontingent, Abmessungen): als
                    // Dateianhang versuchen, damit der Inhalt nicht verloren geht.
                    $report->skip($fileName, $e->getMessage());
                }
            }

            try {
                $this->files->upload($user, $pageId, $this->uploadedFile($bytes, $fileName));
                ++$report->files;
            } catch (\Throwable $e) {
                $report->skip($fileName, $e->getMessage());
            }

            return $resolved[$cacheKey] = [
                'kind' => 'text',
                'text' => $label !== '' ? $label : $fileName,
            ];
        };
    }

    /**
     * Verweisziel auf einen Archiveintrag abbilden. Externe Schemata und
     * Sprungmarken bleiben unangetastet.
     *
     * @param array<string, int> $entries
     */
    private function resolveEntry(array $entries, string $noteEntry, string $target): ?string
    {
        if ($target === '' || preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $target) === 1 || $target[0] === '#') {
            return null;
        }

        $decoded = ltrim(rawurldecode($target), '/');
        $decoded = preg_replace('#^\./#', '', $decoded) ?? $decoded;
        if ($decoded === '' || str_contains($decoded, '..')) {
            return null;
        }

        $directory = dirname($noteEntry);
        $directory = $directory === '.' ? '' : $directory . '/';

        foreach ([$directory . $decoded, $directory . 'Files/' . basename($decoded)] as $candidate) {
            if (isset($entries[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Der Seitentitel steht in der Anwendung über dem Inhalt. Wiederholt die
     * erste Überschrift ihn, wäre er doppelt zu sehen.
     */
    private function stripLeadingTitleHeading(string $body, string $title): string
    {
        $trimmed = ltrim($body, "\n");
        if (preg_match('/^#{1,6}\s+(.+?)\s*#*\s*(?:\n|$)/', $trimmed, $match) !== 1) {
            return $body;
        }

        $heading = trim(preg_replace('/\\\\([!"#$%&\'()*+,\-.\/:;<=>?@\[\\\\\]^_`{|}~])/', '$1', $match[1]) ?? $match[1]);
        $heading = trim(str_replace(['**', '__', '*', '_'], '', $heading));

        return mb_strtolower($heading) === mb_strtolower($title)
            ? substr($trimmed, strlen($match[0]))
            : $body;
    }

    /** @param array<string, string> $meta */
    private function applyTimestamps(int $pageId, array $meta): void
    {
        $created = $this->toTimestamp($meta['created'] ?? null);
        $updated = $this->toTimestamp($meta['date'] ?? null) ?? $created;

        if ($created !== null || $updated !== null) {
            $this->pageRepository->setTimestamps($pageId, $created, $updated);
        }
    }

    private function toTimestamp(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            $date = new \DateTimeImmutable(trim($value), new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        return $date->format('Y-m-d\TH:i:s.v\Z');
    }

    private function titleFor(string $entryName, string $body): string
    {
        $title = preg_replace('/\.md$/i', '', basename($entryName)) ?? basename($entryName);
        // Exporte tragen gelegentlich nicht aufgelöste Ressourcenverweise im
        // Dateinamen; als Titel sind sie unbrauchbar.
        $title = preg_replace('/!?\[?\[.*?\]\]?/u', '', $title) ?? $title;
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);

        if ($title === '') {
            $title = $this->converter->firstHeading($body) ?? 'Ohne Titel';
        }

        return mb_substr($title, 0, self::MAX_TITLE_LENGTH);
    }

    private function discardPage(User $user, int $pageId): void
    {
        try {
            $storageNames = $this->images->storageNamesForOwnedPage($user, $pageId);
            $fileNames = $this->files->storageNamesForPage($user, $pageId);
            $this->pages->purge($user, $pageId);
            $this->images->deleteStoredFiles($storageNames);
            $this->files->deleteStoredFiles($fileNames);
        } catch (\Throwable) {
            /* Der Fehler der Notiz selbst ist die Meldung, die zählt. */
        }
    }

    /**
     * @param array<string, int> $entries
     * @param array<string, true> $usedEntries
     */
    private function countUnusedFiles(array $entries, array $usedEntries): int
    {
        $unused = 0;
        foreach ($entries as $name => $size) {
            if ($size === 0 || str_ends_with($name, '/')) {
                continue;
            }
            $lower = strtolower($name);
            if (str_ends_with($lower, '.md') || str_ends_with($lower, '.lnk')) {
                continue;
            }
            if (!isset($usedEntries[$name])) {
                ++$unused;
            }
        }

        return $unused;
    }

    /** @return array<string, int> Eintragsname => unkomprimierte Größe */
    private function indexEntries(ZipArchive $zip): array
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new ValidationException('Das Archiv enthält zu viele Einträge.');
        }

        $entries = [];
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $stat = $zip->statIndex($index);
            if ($stat === false) {
                continue;
            }
            $size = (int) $stat['size'];
            $total += $size;
            // Schutz vor Archiven, die entpackt ein Vielfaches ihrer Größe
            // belegen: Der Import liest jeden Eintrag einzeln in den Speicher.
            if ($total > self::MAX_TOTAL_BYTES) {
                throw new ValidationException('Der Inhalt des Archivs ist zu groß.');
            }
            $entries[(string) $stat['name']] = $size;
        }

        return $entries;
    }

    private function uploadedFile(string $bytes, string $fileName): UploadedFile
    {
        return new UploadedFile(
            new StreamFactory()->createStream($bytes),
            $fileName,
            null,
            strlen($bytes),
            UPLOAD_ERR_OK,
        );
    }

    private function toTemporaryFile(UploadedFileInterface $upload): string
    {
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException('Die Datei konnte nicht hochgeladen werden.');
        }

        $maxBytes = $this->maxArchiveMb() * 1024 * 1024;
        $reported = $upload->getSize();
        if ($reported !== null && $reported > $maxBytes) {
            throw new ValidationException("Das Archiv darf maximal {$this->maxArchiveMb()} MB groß sein.");
        }

        $path = tempnam(sys_get_temp_dir(), 'note-import-');
        if ($path === false) {
            throw new \RuntimeException('Es konnte keine temporäre Datei angelegt werden.');
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            @unlink($path);

            throw new \RuntimeException('Die temporäre Datei ist nicht beschreibbar.');
        }

        $stream = $upload->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $written = 0;
        try {
            while (!$stream->eof()) {
                $chunk = $stream->read(262_144);
                if ($chunk === '') {
                    break;
                }
                $written += strlen($chunk);
                if ($written > $maxBytes) {
                    throw new ValidationException("Das Archiv darf maximal {$this->maxArchiveMb()} MB groß sein.");
                }
                fwrite($handle, $chunk);
            }
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($path);

            throw $e;
        }

        fclose($handle);

        if ($written === 0) {
            @unlink($path);

            throw new ValidationException('Die hochgeladene Datei ist leer.');
        }

        return $path;
    }
}
