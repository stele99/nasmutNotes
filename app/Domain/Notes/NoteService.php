<?php

declare(strict_types=1);

namespace App\Domain\Notes;

use App\Domain\PageService;
use App\Domain\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\NoteAttachmentRepository;
use App\Repositories\NoteContentRepository;
use App\Repositories\NoteVersionRepository;
use App\Repositories\PageRepository;
use App\Support\ForbiddenException;
use App\Support\NotFoundException;
use App\Support\ValidationException;
use PDO;
use PDOException;

final class NoteService
{
    private const MAX_BYTES = 1_000_000;
    private const MAX_VERSIONS = 20;
    private const IDLE_SECONDS = 30 * 60;
    private const EMPTY_DOC = '{"type":"doc","content":[]}';
    private const STATE_PLAIN = 'plain';
    private const STATE_ENCRYPTED = 'encrypted';

    private readonly NoteCryptoEnvelope $cryptoEnvelope;

    public function __construct(
        private readonly PDO $pdo,
        private readonly PageService $pages,
        private readonly PageRepository $pageRepository,
        private readonly NoteContentRepository $noteContents,
        private readonly NoteVersionRepository $noteVersions,
        private readonly NoteAttachmentRepository $attachments,
        private readonly ProseMirrorValidator $validator,
        ?NoteCryptoEnvelope $cryptoEnvelope = null,
        private readonly ?AuditLogRepository $auditLog = null,
    ) {
        $this->cryptoEnvelope = $cryptoEnvelope ?? new NoteCryptoEnvelope();
    }

    /** @return array{content: array<string, mixed>, version: int, encryption_state: string, updated_at: string, last_editor_name: ?string} */
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
     * @return array{content: array<string, mixed>, version: int, encryption_state: string, updated_at: string, last_editor_name: ?string}
     * @throws NoteContentException
     * @throws NoteEncryptionException
     * @throws VersionConflictException bei Versionskonflikt (Kap. 10.4).
     * @throws NoteWriteUnavailableException wenn SQLite den Schreibzugriff nicht reservieren kann.
     */
    public function save(
        User $user,
        int $pageId,
        array $content,
        int $expectedVersion,
        bool $forceSnapshot = false,
        string $expectedEncryptionState = self::STATE_PLAIN,
    ): array {
        $page = $this->pages->find($user, $pageId);
        $this->assertIsNotePage($page);
        $this->assertEncryptionState($expectedEncryptionState);
        if ($expectedEncryptionState === self::STATE_ENCRYPTED) {
            $this->cryptoEnvelope->validate($content, (int) $page['id']);
            $contentText = '';
        } else {
            $this->validatePlainContent($content, (int) $page['id']);
            $contentText = $this->validator->extractText($content);
        }
        $this->pages->assertCanWrite($user, $pageId);

        $encoded = $this->encodeContent($content, $expectedEncryptionState === self::STATE_ENCRYPTED);
        $pageId = (int) $page['id'];

        $transactionStarted = false;
        try {
            $this->beginImmediateTransaction();
            $transactionStarted = true;
            $this->pages->assertCanWrite($user, $pageId);

            $lockedPage = $this->pages->find($user, $pageId);
            $currentState = $this->encryptionState($lockedPage);

            $current = $this->noteContents->find($pageId);
            if ($current === null) {
                throw new NotFoundException("Notizinhalt für Seite #{$pageId} nicht gefunden.");
            }

            if ($currentState !== $expectedEncryptionState) {
                throw $this->stateConflict($current, $currentState);
            }

            if ((int) $current['version'] !== $expectedVersion) {
                throw $this->versionConflict($current);
            }

            if ((string) $current['content'] === $encoded) {
                $this->commitTransaction();
                $transactionStarted = false;

                return $this->mapContent($current);
            }

            if (
                $currentState === self::STATE_PLAIN
                && $this->shouldSnapshot($current, $user, $encoded, $forceSnapshot)
            ) {
                $this->createSnapshot(
                    $pageId,
                    (string) $current['content'],
                    $current['updated_by'] !== null ? (int) $current['updated_by'] : null,
                );
            }

            $updatedAt = gmdate('Y-m-d\TH:i:s.v\Z');
            $saved = $this->noteContents->saveIfVersionMatches(
                $pageId,
                $encoded,
                $contentText,
                $expectedVersion,
                $user->id,
                $updatedAt,
            );

            if (!$saved) {
                $this->rollBackTransaction();
                $transactionStarted = false;

                throw $this->freshVersionConflict($pageId);
            }

            $this->pageRepository->touchUpdatedAt($pageId, $updatedAt);
            $this->commitTransaction();
            $transactionStarted = false;

            return $this->savedContent($content, $expectedVersion + 1, $currentState, $updatedAt, $user);
        } catch (VersionConflictException $e) {
            if ($transactionStarted) {
                $this->rollBackTransaction();
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($transactionStarted) {
                $this->rollBackTransaction();
            }
            if ($e instanceof PDOException && $this->isSqliteBusy($e)) {
                throw new NoteWriteUnavailableException($e);
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $content
     * @return array{content: array<string, mixed>, version: int, encryption_state: string, updated_at: string, last_editor_name: ?string}
     * @throws NoteContentException
     * @throws NoteEncryptionException
     * @throws NoteWriteUnavailableException
     * @throws VersionConflictException
     */
    public function transitionEncryption(
        User $user,
        int $pageId,
        string $transition,
        array $content,
        int $expectedVersion,
        string $expectedEncryptionState,
    ): array {
        if (!in_array($transition, ['encrypt', 'rewrap', 'decrypt'], true)) {
            throw new ValidationException('Ungültiger Verschlüsselungsübergang.');
        }
        $this->assertEncryptionState($expectedEncryptionState);
        $requiredState = $transition === 'encrypt' ? self::STATE_PLAIN : self::STATE_ENCRYPTED;
        if ($expectedEncryptionState !== $requiredState) {
            throw new NoteEncryptionException(
                'ENCRYPTION_STATE_CONFLICT',
                'Der angegebene Ausgangszustand passt nicht zum Übergang.',
                409,
            );
        }

        $page = $this->pages->find($user, $pageId);
        $this->assertIsNotePage($page);
        $this->assertOwner($page);
        if (($page['can_edit'] ?? false) !== true) {
            throw new ForbiddenException('Seiten im Papierkorb können nicht verschlüsselt werden.');
        }
        $pageId = (int) $page['id'];

        if ($transition === 'decrypt') {
            $this->validatePlainContent($content, $pageId);
            $contentText = $this->validator->extractText($content);
        } else {
            $this->cryptoEnvelope->validate($content, $pageId);
            $contentText = '';
        }
        $encoded = $this->encodeContent($content, $transition !== 'decrypt');

        // Muss vor den Lösch- und Update-Anweisungen dieser Transaktion aktiv sein.
        $this->pdo->exec('PRAGMA secure_delete = ON');
        $transactionStarted = false;
        try {
            $this->beginImmediateTransaction();
            $transactionStarted = true;

            $lockedPage = $this->pages->findOwned($user, $pageId);
            $this->assertIsNotePage($lockedPage);
            $currentState = $this->encryptionState($lockedPage);
            $current = $this->noteContents->find($pageId);
            if ($current === null) {
                throw new NotFoundException("Notizinhalt für Seite #{$pageId} nicht gefunden.");
            }
            if ($currentState !== $expectedEncryptionState) {
                throw $this->stateConflict($current, $currentState);
            }
            if ((int) $current['version'] !== $expectedVersion) {
                throw $this->versionConflict($current);
            }

            if ($transition === 'encrypt') {
                $this->assertCanEncrypt($pageId);
            } elseif ($transition === 'rewrap') {
                $this->assertRewrapPayloadUnchanged($current, $content);
            }

            $updatedAt = gmdate('Y-m-d\TH:i:s.v\Z');
            if (!$this->noteContents->saveIfVersionMatches(
                $pageId,
                $encoded,
                $contentText,
                $expectedVersion,
                $user->id,
                $updatedAt,
            )) {
                throw $this->freshVersionConflict($pageId);
            }

            $targetEncrypted = $transition !== 'decrypt';
            if ($transition !== 'rewrap') {
                $this->pageRepository->setEncryptionState($pageId, $targetEncrypted, $updatedAt);
            } else {
                $this->pageRepository->touchUpdatedAt($pageId, $updatedAt);
            }

            if ($transition === 'encrypt') {
                $this->noteVersions->deleteForPage($pageId);
                $this->purgeSearchContent($pageId);
            }

            $this->auditLog?->log(
                $user->id,
                match ($transition) {
                    'encrypt' => 'note_encryption_enabled',
                    'rewrap' => 'note_encryption_rewrapped',
                    default => 'note_encryption_disabled',
                },
                'page',
                $pageId,
                null,
                ['from' => $currentState, 'to' => $targetEncrypted ? self::STATE_ENCRYPTED : self::STATE_PLAIN],
            );

            $this->commitTransaction();
            $transactionStarted = false;

            return $this->savedContent(
                $content,
                $expectedVersion + 1,
                $targetEncrypted ? self::STATE_ENCRYPTED : self::STATE_PLAIN,
                $updatedAt,
                $user,
            );
        } catch (\Throwable $e) {
            if ($transactionStarted) {
                $this->rollBackTransaction();
            }
            if ($e instanceof PDOException && $this->isSqliteBusy($e)) {
                throw new NoteWriteUnavailableException($e);
            }
            throw $e;
        }
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
        $this->assertNotEncrypted($page);

        $versions = [];
        foreach ($this->noteVersions->listForPage((int) $page['id']) as $row) {
            $versions[] = $this->mapVersionSummary($row);
        }

        return [
            'versions' => $versions,
            'can_restore' => ($page['is_shared'] ?? false) !== true && ($page['can_edit'] ?? false) === true,
        ];
    }

    /**
     * @return array{id: int, created_at: string, created_by_name: ?string, content: array<string, mixed>}
     */
    public function getVersion(User $user, int $pageId, int $versionId): array
    {
        $page = $this->pages->find($user, $pageId);
        $this->assertIsNotePage($page);
        $this->assertNotEncrypted($page);

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
     * @throws NoteEncryptionException
     * @throws VersionConflictException bei einer zwischenzeitlich geänderten Notiz.
     * @throws NoteWriteUnavailableException wenn SQLite den Schreibzugriff nicht reservieren kann.
     */
    public function restoreVersion(User $user, int $pageId, int $versionId, int $expectedVersion): array
    {
        $page = $this->pages->find($user, $pageId);
        $this->assertIsNotePage($page);
        $this->assertNotEncrypted($page);
        if (($page['is_shared'] ?? false) === true) {
            throw new ForbiddenException('Nur der Eigentümer kann den Versionsverlauf wiederherstellen.');
        }
        if (($page['can_edit'] ?? false) !== true) {
            throw new ForbiddenException('Versionen können für Seiten im Papierkorb nicht wiederhergestellt werden.');
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

        $transactionStarted = false;
        try {
            $this->beginImmediateTransaction();
            $transactionStarted = true;
            $this->pages->assertCanWrite($user, $pageId);

            $lockedPage = $this->pages->find($user, $pageId);
            $this->assertNotEncrypted($lockedPage);

            $current = $this->noteContents->find($pageId);
            if ($current === null) {
                throw new NotFoundException("Notizinhalt für Seite #{$pageId} nicht gefunden.");
            }

            if ((int) $current['version'] !== $expectedVersion) {
                throw $this->versionConflict($current);
            }

            if ((string) $current['content'] === $encoded) {
                $this->commitTransaction();
                $transactionStarted = false;

                return $this->mapContent($current);
            }

            if ($this->shouldSnapshot($current, $user, $encoded, true)) {
                $this->createSnapshot(
                    $pageId,
                    (string) $current['content'],
                    $current['updated_by'] !== null ? (int) $current['updated_by'] : null,
                );
            }

            $updatedAt = gmdate('Y-m-d\TH:i:s.v\Z');
            $saved = $this->noteContents->saveIfVersionMatches(
                $pageId,
                $encoded,
                $contentText,
                $expectedVersion,
                $user->id,
                $updatedAt,
            );

            if (!$saved) {
                $this->rollBackTransaction();
                $transactionStarted = false;

                throw $this->freshVersionConflict($pageId);
            }

            $this->pageRepository->touchUpdatedAt($pageId, $updatedAt);
            $this->commitTransaction();
            $transactionStarted = false;

            return $this->savedContent(
                $restoredContent,
                $expectedVersion + 1,
                self::STATE_PLAIN,
                $updatedAt,
                $user,
            );
        } catch (\Throwable $e) {
            if ($transactionStarted) {
                $this->rollBackTransaction();
            }
            if ($e instanceof PDOException && $this->isSqliteBusy($e)) {
                throw new NoteWriteUnavailableException($e);
            }
            throw $e;
        }
    }

    private function beginImmediateTransaction(): void
    {
        // Reserviert den SQLite-Schreibzugriff vor dem Read-Modify-Write-Ablauf.
        $this->pdo->exec('BEGIN IMMEDIATE');
    }

    private function commitTransaction(): void
    {
        $this->pdo->exec('COMMIT');
    }

    private function rollBackTransaction(): void
    {
        try {
            $this->pdo->exec('ROLLBACK');
        } catch (PDOException) {
            // Ein fehlgeschlagener Commit kann die Transaktion bereits beendet haben.
        }
    }

    /** @param array<string, mixed> $current */
    private function versionConflict(array $current): VersionConflictException
    {
        return new VersionConflictException(
            json_decode((string) $current['content'], true) ?? ['type' => 'doc', 'content' => []],
            (int) $current['version'],
            (string) $current['updated_at'],
            $current['last_editor_name'] !== null ? (string) $current['last_editor_name'] : null,
            ((bool) ($current['is_encrypted'] ?? false)) ? self::STATE_ENCRYPTED : self::STATE_PLAIN,
        );
    }

    private function freshVersionConflict(int $pageId): VersionConflictException
    {
        $current = $this->noteContents->find($pageId);
        if ($current === null) {
            throw new NotFoundException("Notizinhalt für Seite #{$pageId} nicht gefunden.");
        }

        return $this->versionConflict($current);
    }

    /**
     * @param array<string, mixed> $content
     * @return array{content: array<string, mixed>, version: int, encryption_state: string, updated_at: string, last_editor_name: ?string}
     */
    private function savedContent(
        array $content,
        int $version,
        string $encryptionState,
        string $updatedAt,
        User $user,
    ): array {
        return [
            'content' => $content,
            'version' => $version,
            'encryption_state' => $encryptionState,
            'updated_at' => $updatedAt,
            'last_editor_name' => $user->name,
        ];
    }

    private function isSqliteBusy(PDOException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'database is locked')
            || str_contains($message, 'database is busy')
            || str_contains($message, 'database table is locked');
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
     * @return array{content: array<string, mixed>, version: int, encryption_state: string, updated_at: string, last_editor_name: ?string}
     */
    private function mapContent(array $row): array
    {
        return [
            'content' => json_decode((string) $row['content'], true) ?? ['type' => 'doc', 'content' => []],
            'version' => (int) $row['version'],
            'encryption_state' => ((bool) ($row['is_encrypted'] ?? false))
                ? self::STATE_ENCRYPTED
                : self::STATE_PLAIN,
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

    /** @param array<string, mixed> $page */
    private function assertOwner(array $page): void
    {
        if (($page['is_shared'] ?? false) === true) {
            throw new NoteEncryptionException(
                'ENCRYPTION_OWNER_REQUIRED',
                'Nur der Eigentümer kann verschlüsselte Notizen speichern oder ihren Zustand ändern.',
                403,
            );
        }
    }

    /** @param array<string, mixed> $page */
    private function assertNotEncrypted(array $page): void
    {
        if ($this->encryptionState($page) === self::STATE_ENCRYPTED) {
            throw new NoteEncryptionException(
                'NOTE_ENCRYPTED',
                'Diese Funktion ist für verschlüsselte Notizen nicht verfügbar.',
            );
        }
    }

    /** @param array<string, mixed> $page */
    private function encryptionState(array $page): string
    {
        return ((bool) ($page['is_encrypted'] ?? false)) ? self::STATE_ENCRYPTED : self::STATE_PLAIN;
    }

    private function assertEncryptionState(string $state): void
    {
        if (!in_array($state, [self::STATE_PLAIN, self::STATE_ENCRYPTED], true)) {
            throw new ValidationException('Ungültiger Verschlüsselungszustand.');
        }
    }

    /** @param array<string, mixed> $content */
    private function validatePlainContent(array $content, int $pageId): void
    {
        $this->validator->validate($content);
        $attachmentHashes = array_map(
            static fn (string $token): string => hash('sha256', $token),
            $this->validator->attachmentTokens($content),
        );
        if (!$this->attachments->allBelongToPage($pageId, $attachmentHashes)) {
            throw new NoteContentException('Mindestens ein Bild gehört nicht zu dieser Notizseite.');
        }
    }

    /** @param array<string, mixed> $content */
    private function encodeContent(array $content, bool $encrypted): string
    {
        $encoded = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new NoteEncryptionException(
                $encrypted ? 'INVALID_CRYPTO_ENVELOPE' : 'INVALID_NOTE_CONTENT',
                'Der Notizinhalt kann nicht als JSON gespeichert werden.',
            );
        }
        if (strlen($encoded) > self::MAX_BYTES) {
            throw new NoteEncryptionException(
                'CONTENT_TOO_LARGE',
                'Der Notizinhalt überschreitet die maximale Größe von 1 MB.',
            );
        }

        return $encoded;
    }

    /** @param array<string, mixed> $current */
    private function stateConflict(array $current, string $currentState): NoteEncryptionException
    {
        return new NoteEncryptionException(
            'ENCRYPTION_STATE_CONFLICT',
            'Der Verschlüsselungszustand der Notiz hat sich geändert.',
            409,
            ['current' => $this->mapContent($current), 'encryption_state' => $currentState],
        );
    }

    private function assertCanEncrypt(int $pageId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM share_links
              WHERE page_id = :page_id AND revoked_at IS NULL
                AND mode = 'read_copy'
                AND (expires_at IS NULL OR expires_at > :now)"
        );
        $stmt->execute(['page_id' => $pageId, 'now' => gmdate('Y-m-d\TH:i:s.v\Z')]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new NoteEncryptionException(
                'ENCRYPTION_HAS_COPY_SHARE',
                'Eine Lesen-und-Kopieren-Freigabe muss vor dem Verschlüsseln beendet werden.',
                409,
            );
        }

        $stmt = $this->pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM note_attachments WHERE page_id = :image_page_id)
                  + (SELECT COUNT(*) FROM page_attachments WHERE page_id = :file_page_id)'
        );
        $stmt->execute(['image_page_id' => $pageId, 'file_page_id' => $pageId]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new NoteEncryptionException(
                'ENCRYPTION_HAS_ATTACHMENTS',
                'Die Notiz besitzt noch Bilder oder Dateianhänge.',
                409,
            );
        }
    }

    /** @param array<string, mixed> $current
     *  @param array<string, mixed> $next
     */
    private function assertRewrapPayloadUnchanged(array $current, array $next): void
    {
        $stored = json_decode((string) $current['content'], true);
        if (
            !is_array($stored)
            || ($stored['payload']['iv'] ?? null) !== ($next['payload']['iv'] ?? null)
            || ($stored['payload']['data'] ?? null) !== ($next['payload']['data'] ?? null)
        ) {
            throw new NoteEncryptionException(
                'ENCRYPTION_REWRAP_PAYLOAD_CHANGED',
                'Beim Kennwortwechsel darf der verschlüsselte Nutzinhalt nicht verändert werden.',
                409,
            );
        }
    }

    private function purgeSearchContent(int $pageId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE search_documents SET body = '', meta = '', updated_at = :now WHERE page_id = :page_id"
        );
        $stmt->execute(['page_id' => $pageId, 'now' => gmdate('Y-m-d\TH:i:s.v\Z')]);
    }
}
