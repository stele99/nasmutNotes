<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Domain\Export\MarkdownRenderer;
use App\Domain\Export\NotebookExportService;
use App\Repositories\AuditLogRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\LogRepository;
use App\Repositories\NoteAttachmentRepository;
use App\Repositories\NotebookRepository;
use App\Repositories\NoteContentRepository;
use App\Repositories\PageAttachmentRepository;
use App\Repositories\PageRepository;
use App\Repositories\TaskRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\AdminEmails;
use App\Support\Database;
use App\Support\Migrator;
use App\Support\NotFoundException;
use App\Support\UploadStorage;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Holt den Stand eines einzelnen Nutzers aus einer Sicherung heraus - als ZIP
 * im Format des Notiz-Exports, das der Nutzer über den normalen Import wieder
 * einspielt.
 *
 * Damit lässt sich eine versehentlich endgültig gelöschte Seite zurückholen,
 * ohne per `backup:restore` die Änderungen aller anderen Nutzer mit
 * zurückzudrehen. Gearbeitet wird ausschließlich auf einer Kopie des
 * Datenbankabzugs; die Sicherung selbst bleibt unberührt.
 */
final class BackupExtractor
{
    public function __construct(
        private readonly BackupLayout $layout,
        private readonly string $migrationsPath,
    ) {
    }

    /**
     * @param bool $includeTrash Seiten, die zum Sicherungszeitpunkt im Papierkorb
     *                           lagen, wie aktive Seiten mitnehmen
     *
     * @return array{path: string, pages: int, files: int}
     */
    public function extract(string $id, string $email, string $targetPath, bool $includeTrash = false): array
    {
        $manifest = $this->layout->manifest($id);
        $databaseFile = $this->layout->basePath() . '/' . $manifest['database']['file'];
        if (!is_file($databaseFile) || hash_file('sha256', $databaseFile) !== $manifest['database']['sha256']) {
            throw new \RuntimeException('Der Datenbankabzug fehlt oder ist beschädigt.');
        }

        $this->layout->ensureDirectories();
        $work = $this->layout->tmpPath() . '/extract-' . bin2hex(random_bytes(8));
        if (!mkdir($work . '/uploads', 0750, true)) {
            throw new \RuntimeException('Das Arbeitsverzeichnis konnte nicht angelegt werden.');
        }

        try {
            $copy = $work . '/snapshot.sqlite';
            if (!copy($databaseFile, $copy)) {
                throw new \RuntimeException('Der Datenbankabzug konnte nicht kopiert werden.');
            }

            // Ältere Abzüge auf das aktuelle Schema heben - die Repositories
            // erwarten die Spalten von heute.
            $pdo = Database::connect($copy);
            (new Migrator($pdo, $this->migrationsPath))->migrate();

            $user = (new UserRepository($pdo, new AdminEmails('')))->findByEmail($email);
            if ($user === null) {
                throw new NotFoundException("In dieser Sicherung gibt es kein Konto {$email}.");
            }
            $workspaces = new WorkspaceRepository($pdo);
            $workspaceId = $workspaces->findByUserId($user->id);
            if ($workspaceId === null) {
                throw new NotFoundException('Zu diesem Konto gehört in der Sicherung kein Workspace.');
            }

            if ($includeTrash) {
                $stmt = $pdo->prepare('UPDATE pages SET deleted_at = NULL WHERE workspace_id = :workspace_id');
                $stmt->execute(['workspace_id' => $workspaceId]);
            }

            $this->materializeUploads($manifest['uploads']['files'], $this->pageIds($pdo, $workspaceId), $work);

            $export = new NotebookExportService(
                $workspaces,
                new NotebookRepository($pdo),
                new PageRepository($pdo),
                new NoteContentRepository($pdo),
                new NoteAttachmentRepository($pdo),
                new PageAttachmentRepository($pdo),
                new CategoryRepository($pdo),
                new TaskRepository($pdo),
                new LogRepository($pdo),
                new UploadStorage($work, $work . '/uploads'),
                new MarkdownRenderer(),
                new AuditLogRepository($pdo),
                $work . '/export',
            );

            $notebookIds = [];
            $includeUnassigned = false;
            foreach ($export->selectable($user) as $entry) {
                if ($entry['id'] === null) {
                    $includeUnassigned = true;
                } else {
                    $notebookIds[] = $entry['id'];
                }
            }

            $result = $export->export($user, $notebookIds, $includeUnassigned);
            unset($pdo);

            $directory = dirname($targetPath);
            if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
                throw new \RuntimeException("Zielverzeichnis fehlt: {$directory}");
            }
            if (!rename($result['path'], $targetPath) && !copy($result['path'], $targetPath)) {
                throw new \RuntimeException("Das Archiv konnte nicht nach {$targetPath} geschrieben werden.");
            }

            return ['path' => $targetPath, 'pages' => $result['pages'], 'files' => $result['files']];
        } finally {
            $this->removeTree($work);
        }
    }

    /**
     * @return array<int, true>
     */
    private function pageIds(\PDO $pdo, int $workspaceId): array
    {
        $stmt = $pdo->prepare('SELECT id FROM pages WHERE workspace_id = :workspace_id');
        $stmt->execute(['workspace_id' => $workspaceId]);

        $ids = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $ids[(int) $id] = true;
        }

        return $ids;
    }

    /**
     * Legt nur die Dateien der betroffenen Seiten aus dem Pool ab. Der
     * Speichername verrät die Seite (`notes/<id>/…`, `files/<id>/…`).
     *
     * @param array<int, array{path: string, bytes: int, mtime: int, sha256: string}> $files
     * @param array<int, true> $pageIds
     */
    private function materializeUploads(array $files, array $pageIds, string $work): void
    {
        foreach ($files as $entry) {
            if (preg_match('#^(?:notes|files)/([1-9][0-9]*)/[^/]+$#', $entry['path'], $match) !== 1) {
                continue;
            }
            if (!isset($pageIds[(int) $match[1]])) {
                continue;
            }
            $source = $this->layout->poolPath($entry['sha256']);
            if (!is_file($source)) {
                continue;
            }
            $target = $work . '/uploads/' . $entry['path'];
            $directory = dirname($target);
            if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
                throw new \RuntimeException("Verzeichnis konnte nicht angelegt werden: {$directory}");
            }
            if (!copy($source, $target)) {
                throw new \RuntimeException("Datei konnte nicht bereitgestellt werden: {$entry['path']}");
            }
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
