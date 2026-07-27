<?php

declare(strict_types=1);

namespace App\Domain\Notes;

use App\Domain\PageService;
use App\Domain\User;
use App\Repositories\NoteAttachmentRepository;
use App\Repositories\NoteContentRepository;
use App\Repositories\NoteVersionRepository;
use App\Repositories\PageRepository;
use App\Support\ForbiddenException;
use App\Support\NotFoundException;
use App\Support\ValidationException;
use PDO;

final class NoteService
{
    private const MAX_BYTES = 1_000_000;
    private const MAX_VERSIONS = 20;
    private const IDLE_SECONDS = 30 * 60;
    private const EMPTY_DOC = '{"type":"doc","content":[]}';

    public function __construct(
        private readonly PDO $pdo,
        private readonly PageService $pages,
        private readonly PageRepository $pageRepository,
        private readonly NoteContentRepository $noteContents,
        private readonly NoteVersionRepository $noteVersions,
        private readonly NoteAttachmentRepository $attachments,
        private readonly ProseMirrorValidator $validator,
    ) {
    }

    /** @return array{content: array<string, mixed>, version: int, updated_at: string, last_editor_name: ?string} */
    public function get(User $user, int $pageId): array
    {
        $page = $this->pages->find($user, $pageId);
        $this->assertIsNotePage($page);

        $row = $this->noteContents->find((int) $page['id']);
        if ($row === null) {
            throw new NotFoundException("Notizinhalt für Seite #{$pageId} nicht gefunden.");
        }

        return $this->mapContent($row);
    }

    /**
     * @param array<string, mixed> $content
     * @return array{content: array<string, mixed>, version: int, updated_at: string, last_editor_name: ?string}
     * @throws VersionConflictException bei Versionskonflikt (Kap. 10.4).
     */
    public function save(User $user, int $pageId, array $content, int $expectedVersion, bool $forceSnapshot = false): array
    {
        $page = $this->pages->find($user, $pageId);
        $this->assertIsNotePage($page);
        $this->pages->assertCanWrite($user, $pageId);

        $this->validator->validate($content);
        $attachmentHashes = array_map(
            static fn (string $token): string => hash('sha256', $token),
            $this->validator->attachmentTokens($content),
        );
        if (!$this->attachments->allBelongToPage((int) $page['id'], $attachmentHashes)) {
            throw new NoteContentException('Mindestens ein Bild gehört nicht zu dieser Notizseite.');
        }

        $encoded = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || strlen($encoded) > self::MAX_BYTES) {
            throw new ValidationException('Der Notizinhalt überschreitet die maximale Größe von 1 MB.');
        }

        $contentText = $this->validator->extractText($content);
        $pageId = (int) $page['id'];

        $this->pdo->beginTransaction();
        try {
            $current = $this->noteContents->find($pageId);
            if ($current === null) {
                throw new NotFoundException("Notizinhalt für Seite #{$pageId} nicht gefunden.");
            }

            if ((int) $current['version'] !== $expectedVersion) {
                $this->pdo->rollBack();
                throw new VersionConflictException(
                    json_decode((string) $current['content'], true) ?? ['type' => 'doc', 'content' => []],
                    (int) $current['version'],
                    (string) $current['updated_at'],
                    $current['last_editor_name'] !== null ? (string) $current['last_editor_name'] : null,
                );
            }

            if ($this->shouldSnapshot($current, $user, $encoded, $forceSnapshot)) {
                $this->createSnapshot(
                    $pageId,
                    (string) $current['content'],
                    $current['updated_by'] !== null ? (int) $current['updated_by'] : null,
                );
            }

            $saved = $this->noteContents->saveIfVersionMatches(
                $pageId,
                $encoded,
                $contentText,
                $expectedVersion,
                $user->id,
            );

            if (!$saved) {
                $this->pdo->rollBack();
                $fresh = $this->noteContents->find($pageId);
                assert($fresh !== null);

                throw new VersionConflictException(
                    json_decode((string) $fresh['content'], true) ?? ['type' => 'doc', 'content' => []],
                    (int) $fresh['version'],
                    (string) $fresh['updated_at'],
                    $fresh['last_editor_name'] !== null ? (string) $fresh['last_editor_name'] : null,
                );
            }

            $this->pageRepository->touchUpdatedAt($pageId);
            $this->pdo->commit();
        } catch (VersionConflictException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->get($user, $pageId);
    }

    /**
     * @return array{
     *     versions: array<int, array{id: int, created_at: string, created_by_name: ?string, preview: string}>,
     *     can_restore: bool
     * }
     */
    public function listVersions(User $user, int $pageId): array
    {
        $page = $this->pages->find($user, $pageId);
        $this->assertIsNotePage($page);

        $versions = [];
        foreach ($this->noteVersions->listForPage((int) $page['id']) as $row) {
            $versions[] = $this->mapVersionSummary($row);
        }

        return [
            'versions' => $versions,
            'can_restore' => ($page['is_shared'] ?? false) !== true,
        ];
    }

    /**
     * @return array{id: int, created_at: string, created_by_name: ?string, content: array<string, mixed>}
     */
    public function getVersion(User $user, int $pageId, int $versionId): array
    {
        $page = $this->pages->find($user, $pageId);
        $this->assertIsNotePage($page);

        $row = $this->noteVersions->findForPage((int) $page['id'], $versionId);
        if ($row === null) {
            throw new NotFoundException("Version #{$versionId} nicht gefunden.");
        }

        return [
            'id' => (int) $row['id'],
            'created_at' => (string) $row['created_at'],
            'created_by_name' => $row['created_by_name'] !== null ? (string) $row['created_by_name'] : null,
            'content' => json_decode((string) $row['content'], true) ?? ['type' => 'doc', 'content' => []],
        ];
    }

    /**
     * @return array{content: array<string, mixed>, version: int, updated_at: string, last_editor_name: ?string}
     */
    public function restoreVersion(User $user, int $pageId, int $versionId): array
    {
        $page = $this->pages->find($user, $pageId);
        $this->assertIsNotePage($page);
        if (($page['is_shared'] ?? false) === true) {
            throw new ForbiddenException('Nur der Eigentümer kann den Versionsverlauf wiederherstellen.');
        }
        $pageId = (int) $page['id'];

        $snapshot = $this->noteVersions->findForPage($pageId, $versionId);
        if ($snapshot === null) {
            throw new NotFoundException("Version #{$versionId} nicht gefunden.");
        }

        $restoredContent = json_decode((string) $snapshot['content'], true);
        if (!is_array($restoredContent)) {
            throw new NoteContentException('Gespeicherte Version enthält ungültigen Inhalt.');
        }

        $this->validator->validate($restoredContent);
        $attachmentHashes = array_map(
            static fn (string $token): string => hash('sha256', $token),
            $this->validator->attachmentTokens($restoredContent),
        );
        if (!$this->attachments->allBelongToPage($pageId, $attachmentHashes)) {
            throw new NoteContentException('Mindestens ein Bild aus dieser Version ist nicht mehr verfügbar.');
        }

        $encoded = (string) $snapshot['content'];
        if (strlen($encoded) > self::MAX_BYTES) {
            throw new ValidationException('Der Notizinhalt überschreitet die maximale Größe von 1 MB.');
        }

        $contentText = $this->validator->extractText($restoredContent);

        $this->pdo->beginTransaction();
        try {
            $current = $this->noteContents->find($pageId);
            if ($current === null) {
                throw new NotFoundException("Notizinhalt für Seite #{$pageId} nicht gefunden.");
            }

            if ($this->shouldSnapshot($current, $user, $encoded, true)) {
                $this->createSnapshot(
                    $pageId,
                    (string) $current['content'],
                    $current['updated_by'] !== null ? (int) $current['updated_by'] : null,
                );
            }

            $saved = $this->noteContents->saveIfVersionMatches(
                $pageId,
                $encoded,
                $contentText,
                (int) $current['version'],
                $user->id,
            );

            if (!$saved) {
                throw new VersionConflictException(
                    json_decode((string) $current['content'], true) ?? ['type' => 'doc', 'content' => []],
                    (int) $current['version'],
                    (string) $current['updated_at'],
                    $current['last_editor_name'] !== null ? (string) $current['last_editor_name'] : null,
                );
            }

            $this->pageRepository->touchUpdatedAt($pageId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->get($user, $pageId);
    }

    /**
     * @param array<string, mixed> $current
     */
    private function shouldSnapshot(array $current, User $user, string $newEncoded, bool $force): bool
    {
        $oldContent = (string) $current['content'];
        if ($oldContent === $newEncoded || $this->isEmptyDoc($oldContent)) {
            return false;
        }

        if ($force) {
            return true;
        }

        $updatedBy = $current['updated_by'] !== null ? (int) $current['updated_by'] : null;
        if ($updatedBy !== null && $updatedBy !== $user->id) {
            return true;
        }

        $updatedAt = strtotime((string) $current['updated_at']);
        if ($updatedAt === false) {
            return true;
        }

        return (time() - $updatedAt) >= self::IDLE_SECONDS;
    }

    private function createSnapshot(int $pageId, string $content, ?int $createdBy): void
    {
        $this->noteVersions->insert($pageId, $content, $createdBy);
        $this->noteVersions->prune($pageId, self::MAX_VERSIONS);
    }

    private function isEmptyDoc(string $content): bool
    {
        $normalized = preg_replace('/\s+/', '', $content) ?? $content;

        return $normalized === self::EMPTY_DOC || $normalized === '{"type":"doc"}';
    }

    /**
     * @param array<string, mixed> $row
     * @return array{content: array<string, mixed>, version: int, updated_at: string, last_editor_name: ?string}
     */
    private function mapContent(array $row): array
    {
        return [
            'content' => json_decode((string) $row['content'], true) ?? ['type' => 'doc', 'content' => []],
            'version' => (int) $row['version'],
            'updated_at' => (string) $row['updated_at'],
            'last_editor_name' => $row['last_editor_name'] !== null ? (string) $row['last_editor_name'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, created_at: string, created_by_name: ?string, preview: string}
     */
    private function mapVersionSummary(array $row): array
    {
        $content = json_decode((string) $row['content'], true);
        $preview = is_array($content) ? $this->validator->extractText($content) : '';
        if (mb_strlen($preview) > 160) {
            $preview = mb_substr($preview, 0, 157) . '…';
        }
        if ($preview === '') {
            $preview = '(leerer Inhalt)';
        }

        return [
            'id' => (int) $row['id'],
            'created_at' => (string) $row['created_at'],
            'created_by_name' => $row['created_by_name'] !== null ? (string) $row['created_by_name'] : null,
            'preview' => $preview,
        ];
    }

    /** @param array<string, mixed> $page */
    private function assertIsNotePage(array $page): void
    {
        if ($page['type'] !== 'note') {
            throw new NotFoundException('Diese Seite ist keine Notizseite.');
        }
    }
}
