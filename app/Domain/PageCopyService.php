<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Notes\NoteEncryptionException;
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
use App\Support\ValidationException;
use PDO;

final class PageCopyService
{
    private const IMAGE_EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly PageRepository $pages,
        private readonly WorkspaceRepository $workspaces,
        private readonly NotebookRepository $notebooks,
        private readonly NoteContentRepository $noteContents,
        private readonly NoteAttachmentRepository $images,
        private readonly PageAttachmentRepository $files,
        private readonly CategoryRepository $categories,
        private readonly TaskRepository $tasks,
        private readonly LogRepository $log,
        private readonly SettingsRepository $settings,
        private readonly UploadStorage $storage,
        private readonly int $defaultQuotaMb = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $resolvedShare
     * @return array<string, mixed>
     */
    public function copyFromShare(User $recipient, array $resolvedShare, ?int $notebookId): array
    {
        $mode = $resolvedShare['mode'] ?? $resolvedShare['permission'] ?? null;
        if ($mode !== 'read_copy' || !isset($resolvedShare['page_id'])) {
            throw new ValidationException('Nur Freigaben im Modus "read_copy" können kopiert werden.');
        }

        $workspaceId = $this->workspaces->findByUserId($recipient->id);
        if ($workspaceId === null) {
            throw new NotFoundException('Workspace nicht gefunden.');
        }
        if ($notebookId !== null && $this->notebooks->findByIdForWorkspace($notebookId, $workspaceId) === null) {
            throw new NotFoundException('Notizbuch nicht gefunden.');
        }

        $source = $this->pages->findById((int) $resolvedShare['page_id']);
        if ($source === null || $source['deleted_at'] !== null || !in_array($source['type'], ['note', 'task', 'log'], true)) {
            throw new NotFoundException('Quellseite nicht gefunden.');
        }
        if ((bool) ($source['is_encrypted'] ?? false)) {
            throw new NoteEncryptionException(
                'NOTE_ENCRYPTED',
                'Verschlüsselte Notizen können nicht serverseitig kopiert werden.',
            );
        }

        $writtenStorageNames = [];
        $this->pdo->beginTransaction();

        try {
            $copy = $this->pages->create(
                $workspaceId,
                (string) $source['type'],
                (string) $source['title'],
                $source['icon'] !== null ? (string) $source['icon'] : null,
                $notebookId,
            );
            $copyId = (int) $copy['id'];
            $this->pages->updateFields($copyId, ['default_view' => $source['default_view']]);

            if ($source['type'] === 'note') {
                $this->copyNote($source, $copyId, $recipient, $writtenStorageNames);
            } elseif ($source['type'] === 'log') {
                $this->copyLog((int) $source['id'], $copyId, $recipient);
            } else {
                $this->copyTasks((int) $source['id'], $copyId);
            }
            $this->copyPageFiles(
                (int) $source['id'],
                $copyId,
                $recipient,
                $source['type'] !== 'note',
                $writtenStorageNames,
            );

            $this->pdo->commit();

            $result = $this->pages->findByIdForWorkspace($copyId, $workspaceId);
            assert($result !== null);

            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            foreach ($writtenStorageNames as $storageName) {
                $this->storage->delete($storageName);
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $source
     * @param list<string> $writtenStorageNames
     */
    private function copyNote(array $source, int $copyId, User $recipient, array &$writtenStorageNames): void
    {
        $content = $this->noteContents->find((int) $source['id']);
        if ($content === null) {
            throw new NotFoundException('Notizinhalt nicht gefunden.');
        }

        try {
            $document = json_decode((string) $content['content'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ValidationException('Der Notizinhalt ist ungültig.', previous: $exception);
        }
        if (!is_array($document)) {
            throw new ValidationException('Der Notizinhalt ist ungültig.');
        }

        $tokenMap = [];
        $this->collectImageTokens($document, $tokenMap);
        $additionalBytes = 0;
        $sourceImages = [];
        foreach (array_keys($tokenMap) as $token) {
            $image = $this->images->findByTokenHash(hash('sha256', $token));
            if ($image === null || (int) $image['page_id'] !== (int) $source['id']) {
                throw new ValidationException('Ein eingebettetes Bild gehört nicht zur Quellseite.');
            }
            $additionalBytes += (int) $image['byte_size'];
            $sourceImages[$token] = $image;
        }
        $additionalBytes += $this->pageFileBytes((int) $source['id']);
        $this->assertQuota($copyId, $additionalBytes);

        foreach ($sourceImages as $oldToken => $image) {
            $bytes = $this->readStored((string) $image['storage_name']);
            $newToken = bin2hex(random_bytes(32));
            $storageName = $this->storage->writeImage(
                $copyId,
                $bytes,
                self::IMAGE_EXTENSIONS[(string) $image['mime_type']],
            );
            $writtenStorageNames[] = $storageName;
            $this->images->create(
                $copyId,
                hash('sha256', $newToken),
                $storageName,
                $image['original_name'] !== null ? (string) $image['original_name'] : null,
                (string) $image['mime_type'],
                (int) $image['byte_size'],
                (int) $image['width'],
                (int) $image['height'],
                $recipient->id,
            );
            $tokenMap[$oldToken] = $newToken;
        }

        $this->rewriteImageTokens($document, $tokenMap);
        $encoded = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->noteContents->replaceForCopy($copyId, $encoded, (string) $content['content_text'], $recipient->id);
    }

    /**
     * Kopiert Spalten, Einträge und Werte eines Logbuchs. Eine frisch
     * angelegte Logbuch-Seite bekommt automatisch eine Textspalte
     * (`PageRepository::create`) - für eine Kopie muss stattdessen genau der
     * Spaltenbestand der Quelle entstehen, die Vorgabespalte wird deshalb
     * zuerst wieder entfernt.
     */
    private function copyLog(int $sourcePageId, int $copyId, User $recipient): void
    {
        foreach ($this->log->columnsForPage($copyId) as $column) {
            $this->log->deleteColumn((int) $column['id']);
        }

        $columnMap = [];
        foreach ($this->log->columnsForPage($sourcePageId) as $column) {
            $newColumn = $this->log->createColumn(
                $copyId,
                (string) $column['name'],
                (string) $column['type'],
                (int) $column['position'],
            );
            $columnMap[(int) $column['id']] = (int) $newColumn['id'];
        }

        foreach ($this->log->entriesForPage($sourcePageId, null, false) as $entry) {
            $newEntryId = $this->log->createEntry($copyId, (string) $entry['occurred_at'], $recipient->id);
            foreach ((array) ($entry['values'] ?? []) as $columnId => $value) {
                $newColumnId = $columnMap[(int) $columnId] ?? null;
                if ($newColumnId === null) {
                    continue;
                }
                $this->log->setValue($newEntryId, $newColumnId, [
                    'text' => $value['value_text'] !== null ? (string) $value['value_text'] : null,
                    'number' => $value['value_number'] !== null ? (float) $value['value_number'] : null,
                    'lat' => $value['value_lat'] !== null ? (float) $value['value_lat'] : null,
                    'lon' => $value['value_lon'] !== null ? (float) $value['value_lon'] : null,
                ]);
            }
        }
    }

    private function copyTasks(int $sourcePageId, int $copyId): void
    {
        foreach ($this->categories->listForPage($sourcePageId) as $category) {
            $copy = $this->categories->createCopy(
                $copyId,
                (string) $category['name'],
                $category['color'] !== null ? (string) $category['color'] : null,
                (int) $category['position'],
                $category['wip_limit'] !== null ? (int) $category['wip_limit'] : null,
            );
            foreach ($this->tasks->listForCategory((int) $category['id']) as $task) {
                $this->tasks->createCopy((int) $copy['id'], $task);
            }
        }
    }

    /** @param list<string> $writtenStorageNames */
    private function copyPageFiles(
        int $sourcePageId,
        int $copyId,
        User $recipient,
        bool $quotaMustBeChecked,
        array &$writtenStorageNames,
    ): void {
        $sourceFiles = $this->files->listForPage($sourcePageId);
        if ($quotaMustBeChecked) {
            $this->assertQuota($copyId, array_sum(array_map(static fn (array $file): int => (int) $file['byte_size'], $sourceFiles)));
        }

        foreach ($sourceFiles as $file) {
            $storageName = $this->storage->writeFile($copyId, $this->readStored((string) $file['storage_name']));
            $writtenStorageNames[] = $storageName;
            $token = bin2hex(random_bytes(32));
            $this->files->create(
                $copyId,
                hash('sha256', $token),
                $storageName,
                (string) $file['original_name'],
                (string) $file['mime_type'],
                (int) $file['byte_size'],
                $recipient->id,
            );
        }
    }

    private function pageFileBytes(int $pageId): int
    {
        return array_sum(array_map(
            static fn (array $file): int => (int) $file['byte_size'],
            $this->files->listForPage($pageId),
        ));
    }

    private function assertQuota(int $copyId, int $additionalBytes): void
    {
        $quotaMb = $this->images->quotaMbForPageOwner($copyId)
            ?? ($this->settings->getInt(SettingsRepository::DEFAULT_STORAGE_QUOTA_MB, $this->defaultQuotaMb)
                ?? $this->defaultQuotaMb);
        if ($quotaMb <= 0) {
            return;
        }

        $usedBytes = $this->images->usedBytesForPageOwner($copyId) + $this->files->usedBytesForPageOwner($copyId);
        if ($usedBytes + $additionalBytes > $quotaMb * 1024 * 1024) {
            throw new ValidationException("Das Speicherkontingent von {$quotaMb} MB ist erschöpft.");
        }
    }

    private function readStored(string $storageName): string
    {
        $path = $this->storage->path($storageName);
        if ($path === null) {
            throw new NotFoundException('Eine Quelldatei wurde nicht gefunden.');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException('Eine Quelldatei konnte nicht gelesen werden.');
        }

        return $bytes;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, string> $tokens
     */
    private function collectImageTokens(array $node, array &$tokens): void
    {
        $src = ($node['type'] ?? null) === 'image' ? ($node['attrs']['src'] ?? null) : null;
        if (is_string($src) && preg_match('#^/api/attachments/([a-f0-9]{64})$#', $src, $matches) === 1) {
            $tokens[$matches[1]] = '';
        }
        foreach ((array) ($node['content'] ?? []) as $child) {
            if (is_array($child)) {
                $this->collectImageTokens($child, $tokens);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, string> $tokens
     */
    private function rewriteImageTokens(array &$node, array $tokens): void
    {
        $src = ($node['type'] ?? null) === 'image' ? ($node['attrs']['src'] ?? null) : null;
        if (is_string($src) && preg_match('#^/api/attachments/([a-f0-9]{64})$#', $src, $matches) === 1) {
            $node['attrs']['src'] = '/api/attachments/' . $tokens[$matches[1]];
        }
        if (is_array($node['content'] ?? null)) {
            foreach (array_keys($node['content']) as $index) {
                if (is_array($node['content'][$index])) {
                    $this->rewriteImageTokens($node['content'][$index], $tokens);
                }
            }
        }
    }
}
