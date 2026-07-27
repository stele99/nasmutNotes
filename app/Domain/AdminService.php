<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Notes\ImageCompressionService;
use App\Domain\Notes\PageAttachmentService;
use App\Domain\Notes\ProseMirrorValidator;
use App\Repositories\AdminRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\SettingsRepository;
use App\Support\NotFoundException;
use App\Support\UploadStorage;
use App\Support\ValidationException;
use PDO;

/**
 * Admin-Dashboard: Nutzerübersicht mit Speicherbedarf, vollständiges Löschen
 * eines Nutzers samt Inhalten und Aufräumen verwaister Bilddateien
 * (FR-ADM-01..04).
 */
final class AdminService
{
    private const MAX_QUOTA_MB = 1_000_000;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AdminRepository $admin,
        private readonly AuditLogRepository $auditLog,
        private readonly UploadStorage $storage,
        private readonly ProseMirrorValidator $validator,
        private readonly SettingsRepository $settings,
        private readonly ImageCompressionService $imageCompression,
        private readonly int $fallbackQuotaMb = 0,
        private readonly int $fallbackMaxAttachmentMb = 10,
        private readonly int $fallbackOfflineAttachmentKb = PageAttachmentService::DEFAULT_OFFLINE_MAX_KB,
    ) {
    }

    /**
     * Grenze in KB, bis zu der Clients Anhänge automatisch offline vorhalten
     * (FR-OFFLINE-06). 0 schaltet das automatische Vorladen ab.
     */
    public function offlineAttachmentMaxKb(): int
    {
        $value = $this->settings->getInt(
            SettingsRepository::OFFLINE_ATTACHMENT_MAX_KB,
            $this->fallbackOfflineAttachmentKb,
        ) ?? $this->fallbackOfflineAttachmentKb;

        return max(0, $value);
    }

    public function setOfflineAttachmentMaxKb(User $admin, int $maxKb, string $ipHash): int
    {
        if ($maxKb < 0 || $maxKb > PageAttachmentService::MAX_OFFLINE_MAX_KB) {
            throw new ValidationException(
                'Das Offline-Limit muss zwischen 0 (nichts vorladen) und 102.400 KB liegen.'
            );
        }

        $this->settings->set(SettingsRepository::OFFLINE_ATTACHMENT_MAX_KB, (string) $maxKb);
        $this->auditLog->log($admin->id, 'offline_attachment_limit_changed', null, null, $ipHash, [
            'max_kb' => $maxKb,
        ]);

        return $maxKb;
    }

    /** Obergrenze je Dateianhang in MB (FR-NOTE-21). */
    public function maxAttachmentMb(): int
    {
        return max(1, $this->settings->getInt(PageAttachmentService::MAX_ATTACHMENT_MB_KEY, $this->fallbackMaxAttachmentMb)
            ?? $this->fallbackMaxAttachmentMb);
    }

    public function setMaxAttachmentMb(User $admin, int $maxMb, string $ipHash): int
    {
        if ($maxMb < 1 || $maxMb > 2048) {
            throw new ValidationException('Die Obergrenze muss zwischen 1 und 2048 MB liegen.');
        }

        $this->settings->set(PageAttachmentService::MAX_ATTACHMENT_MB_KEY, (string) $maxMb);
        $this->auditLog->log($admin->id, 'max_attachment_changed', null, null, $ipHash, ['max_mb' => $maxMb]);

        return $maxMb;
    }

    /** Standardkontingent in MB; 0 bedeutet "unbegrenzt". */
    public function defaultQuotaMb(): int
    {
        return $this->settings->getInt(SettingsRepository::DEFAULT_STORAGE_QUOTA_MB, $this->fallbackQuotaMb)
            ?? $this->fallbackQuotaMb;
    }

    public function setDefaultQuotaMb(User $admin, int $quotaMb, string $ipHash): int
    {
        $quotaMb = $this->validateQuota($quotaMb);
        $this->settings->set(SettingsRepository::DEFAULT_STORAGE_QUOTA_MB, (string) $quotaMb);
        $this->auditLog->log($admin->id, 'default_quota_changed', null, null, $ipHash, [
            'quota_mb' => $quotaMb,
        ]);

        return $quotaMb;
    }

    /**
     * Setzt das persönliche Kontingent eines Nutzers. null stellt auf den
     * Standardwert zurück.
     */
    public function setUserQuotaMb(User $admin, int $userId, ?int $quotaMb, string $ipHash): ?int
    {
        if ($this->admin->findUser($userId) === null) {
            throw new NotFoundException('Nutzer nicht gefunden.');
        }

        $quotaMb = $quotaMb === null ? null : $this->validateQuota($quotaMb);
        $this->admin->setUserQuota($userId, $quotaMb);
        $this->auditLog->log($admin->id, 'user_quota_changed', 'user', $userId, $ipHash, [
            'quota_mb' => $quotaMb,
        ]);

        return $quotaMb;
    }

    private function validateQuota(int $quotaMb): int
    {
        if ($quotaMb < 0 || $quotaMb > self::MAX_QUOTA_MB) {
            throw new ValidationException('Das Kontingent muss zwischen 0 (unbegrenzt) und 1.000.000 MB liegen.');
        }

        return $quotaMb;
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $defaultQuotaMb = $this->defaultQuotaMb();
        $users = array_map(
            static function (array $row) use ($defaultQuotaMb): array {
                $attachmentBytes = (int) $row['attachment_bytes'];
                $contentBytes = (int) $row['content_bytes'];
                $personalQuota = $row['storage_quota_mb'] !== null ? (int) $row['storage_quota_mb'] : null;

                return [
                    'storage_quota_mb' => $personalQuota,
                    'effective_quota_mb' => $personalQuota ?? $defaultQuotaMb,
                    'id' => (int) $row['id'],
                    'email' => (string) $row['email'],
                    'name' => (string) $row['name'],
                    'is_active' => ((int) $row['is_active']) === 1,
                    'created_at' => $row['created_at'],
                    'last_login_at' => $row['last_login_at'],
                    'page_count' => (int) $row['page_count'],
                    'trashed_page_count' => (int) $row['trashed_page_count'],
                    'task_count' => (int) $row['task_count'],
                    'attachment_count' => (int) $row['attachment_count'],
                    'image_count' => (int) $row['image_count'],
                    'attachment_bytes' => $attachmentBytes,
                    'content_bytes' => $contentBytes,
                    'total_bytes' => $attachmentBytes + $contentBytes,
                ];
            },
            $this->admin->usersWithUsage(),
        );

        $orphans = $this->findOrphanedAttachments();

        return [
            'users' => $users,
            'default_quota_mb' => $defaultQuotaMb,
            'max_attachment_mb' => $this->maxAttachmentMb(),
            'offline_attachment_max_kb' => $this->offlineAttachmentMaxKb(),
            'totals' => [
                'user_count' => count($users),
                'page_count' => array_sum(array_column($users, 'page_count')),
                'attachment_count' => array_sum(array_column($users, 'attachment_count')),
                'image_count' => array_sum(array_column($users, 'image_count')),
                'total_bytes' => array_sum(array_column($users, 'total_bytes')),
            ],
            'orphans' => [
                'count' => count($orphans),
                'bytes' => array_sum(array_map(static fn (array $row): int => (int) $row['byte_size'], $orphans)),
                'items' => array_map(
                    static fn (array $row): array => [
                        'id' => (int) $row['id'],
                        'page_title' => $row['page_title'] !== null ? (string) $row['page_title'] : null,
                        'byte_size' => (int) $row['byte_size'],
                        'created_at' => $row['created_at'],
                    ],
                    array_slice($orphans, 0, 50),
                ),
            ],
        ];
    }

    /** @return array{images: int, compressed: int, skipped: int, before_bytes: int, after_bytes: int, saved_bytes: int} */
    public function compressUserImages(User $admin, int $userId, string $ipHash): array
    {
        if ($this->admin->findUser($userId) === null) {
            throw new NotFoundException('Nutzer nicht gefunden.');
        }

        $result = $this->imageCompression->compressForUser($userId, 82, 'screen');
        $this->auditLog->log($admin->id, 'user_images_compressed', 'user', $userId, $ipHash, $result);

        return $result;
    }

    /**
     * Löscht einen Nutzer mit allen Inhalten. Die Datenbank räumt über
     * ON DELETE CASCADE auf (Workspace, Seiten, Notizen, Aufgaben, Freigaben,
     * Sessions, Einladungen); die Bilddateien auf dem Datenträger müssen
     * vorher eingesammelt und danach gelöscht werden.
     *
     * @return array{deleted_files: int}
     */
    public function deleteUser(User $admin, int $userId, string $ipHash): array
    {
        if ($userId === $admin->id) {
            throw new ValidationException('Das eigene Konto kann nicht gelöscht werden.');
        }

        $user = $this->admin->findUser($userId);
        if ($user === null) {
            throw new NotFoundException('Nutzer nicht gefunden.');
        }

        $storageNames = $this->admin->attachmentStorageNamesForUser($userId);

        $this->pdo->beginTransaction();

        try {
            $this->admin->deleteUser($userId);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        // Erst nach dem Commit: Ein Rollback könnte gelöschte Dateien nicht
        // zurückholen, verwaiste Dateien wären dagegen später aufräumbar.
        $deleted = $this->deleteFiles($storageNames);

        $this->auditLog->log($admin->id, 'user_deleted', 'user', $userId, $ipHash, [
            'email' => (string) $user['email'],
            'deleted_files' => $deleted,
        ]);

        return ['deleted_files' => $deleted];
    }

    /**
     * Entfernt Bilddateien, die in keinem Notizinhalt und in keiner
     * Notizversion mehr vorkommen.
     *
     * @return array{count: int, bytes: int, deleted_files: int}
     */
    public function purgeOrphanedAttachments(User $admin, string $ipHash): array
    {
        $orphans = $this->findOrphanedAttachments();
        if ($orphans === []) {
            return ['count' => 0, 'bytes' => 0, 'deleted_files' => 0];
        }

        $bytes = array_sum(array_map(static fn (array $row): int => (int) $row['byte_size'], $orphans));
        $storageNames = array_values(array_map(
            static fn (array $row): string => (string) $row['storage_name'],
            $orphans,
        ));

        $this->admin->deleteAttachments(array_values(array_map(
            static fn (array $row): int => (int) $row['id'],
            $orphans,
        )));
        $deleted = $this->deleteFiles($storageNames);

        $this->auditLog->log($admin->id, 'attachments_purged', null, null, $ipHash, [
            'count' => count($orphans),
            'bytes' => $bytes,
        ]);

        return ['count' => count($orphans), 'bytes' => $bytes, 'deleted_files' => $deleted];
    }

    /**
     * Ein Anhang gilt als verwaist, wenn sein Token in keinem gespeicherten
     * ProseMirror-Dokument mehr referenziert wird. Verglichen wird über den
     * Hash, weil die Datenbank nur diesen kennt.
     *
     * @return array<int, array<string, mixed>>
     */
    private function findOrphanedAttachments(): array
    {
        $referenced = [];

        foreach ($this->admin->allNoteDocuments() as $json) {
            $document = json_decode($json, true);
            if (!is_array($document)) {
                continue;
            }
            foreach ($this->validator->attachmentTokens($document) as $token) {
                $referenced[hash('sha256', $token)] = true;
            }
        }

        return array_values(array_filter(
            $this->admin->allAttachments(),
            static fn (array $row): bool => !isset($referenced[(string) $row['token_hash']]),
        ));
    }

    /** @param list<string> $storageNames */
    private function deleteFiles(array $storageNames): int
    {
        $deleted = 0;
        foreach ($storageNames as $storageName) {
            if ($this->storage->path($storageName) !== null) {
                $this->storage->delete($storageName);
                ++$deleted;
            }
        }

        return $deleted;
    }
}
