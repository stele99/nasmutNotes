<?php

declare(strict_types=1);

namespace App\Domain\Notes;

use App\Domain\PageService;
use App\Domain\User;
use App\Repositories\NoteAttachmentRepository;
use App\Repositories\PageAttachmentRepository;
use App\Repositories\SettingsRepository;
use App\Support\NotFoundException;
use App\Support\UploadStorage;
use App\Support\ValidationException;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Dateianhänge einer Notizseite (FR-NOTE-18..21). Anders als eingebettete
 * Bilder hängen sie an der Seite und erscheinen als Badges unter der
 * Überschrift.
 */
final class PageAttachmentService
{
    /**
     * Anhänge jedes Dateityps sind erlaubt. Der Schutz liegt vollständig in der
     * Auslieferung (PageAttachmentController): Nur PDF geht `inline` heraus,
     * alles andere ausschließlich als Download mit neutralem Content-Type und
     * `nosniff`. Damit kann kein Anhang - etwa HTML oder SVG - im Ursprung der
     * Anwendung zur Ausführung kommen.
     */
    private const FALLBACK_MIME_TYPE = 'application/octet-stream';

    public const MAX_ATTACHMENT_MB_KEY = 'max_attachment_mb';

    /** Voreinstellung des Offline-Limits je Anhang in KB. */
    public const DEFAULT_OFFLINE_MAX_KB = 250;

    /** Obergrenze des Offline-Limits in KB (100 MB). */
    public const MAX_OFFLINE_MAX_KB = 102_400;

    public function __construct(
        private readonly PageService $pages,
        private readonly PageAttachmentRepository $attachments,
        private readonly NoteAttachmentRepository $images,
        private readonly UploadStorage $storage,
        private readonly SettingsRepository $settings,
        private readonly int $fallbackMaxMb = 10,
        private readonly int $fallbackQuotaMb = 0,
        private readonly int $fallbackOfflineMaxKb = self::DEFAULT_OFFLINE_MAX_KB,
    ) {
    }

    /** Vom Admin gesetzte Obergrenze je Anhang in MB. */
    public function maxAttachmentMb(): int
    {
        $value = $this->settings->getInt(self::MAX_ATTACHMENT_MB_KEY, $this->fallbackMaxMb) ?? $this->fallbackMaxMb;

        return max(1, $value);
    }

    /**
     * Grenze in Bytes, bis zu der Anhänge und eingebettete Bilder beim
     * Offline-Prefetch automatisch mitgeladen werden (FR-OFFLINE-06). Größere
     * Dateien bleiben nur online erreichbar; 0 schaltet das Vorladen ab.
     */
    public function offlineAttachmentMaxBytes(): int
    {
        $value = $this->settings->getInt(
            SettingsRepository::OFFLINE_ATTACHMENT_MAX_KB,
            $this->fallbackOfflineMaxKb,
        ) ?? $this->fallbackOfflineMaxKb;

        return max(0, $value) * 1024;
    }

    /** @return array<int, array<string, mixed>> */
    public function listForPage(User $user, int $pageId): array
    {
        $page = $this->pages->find($user, $pageId);

        return array_map(
            [$this, 'serialize'],
            $this->attachments->listForPage((int) $page['id']),
        );
    }

    /** @return array<string, mixed> */
    public function upload(User $user, int $pageId, UploadedFileInterface $file): array
    {
        $page = $this->pages->find($user, $pageId);
        if ($page['type'] !== 'note') {
            throw new NotFoundException('Diese Seite ist keine Notizseite.');
        }
        $this->pages->assertCanWrite($user, $pageId);

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException('Der Anhang konnte nicht hochgeladen werden.');
        }

        $maxMb = $this->maxAttachmentMb();
        $maxBytes = $maxMb * 1024 * 1024;

        $stream = $file->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $bytes = $stream->getContents();
        $byteSize = strlen($bytes);

        if ($byteSize < 1) {
            throw new ValidationException('Die Datei ist leer.');
        }
        if ($byteSize > $maxBytes) {
            throw new ValidationException("Anhänge dürfen maximal {$maxMb} MB groß sein.");
        }

        // Der erkannte Typ dient nur der Anzeige und der Entscheidung, ob der
        // PDF-Betrachter greift - nicht als Zulassungskriterium.
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $mimeType = is_string($detected) && $detected !== '' ? $detected : self::FALLBACK_MIME_TYPE;

        $this->assertQuota((int) $page['id'], $byteSize);

        $originalName = $this->sanitizeName($file->getClientFilename());
        $token = bin2hex(random_bytes(32));
        $storageName = $this->storage->writeFile((int) $page['id'], $bytes);

        try {
            $attachment = $this->attachments->create(
                (int) $page['id'],
                hash('sha256', $token),
                $storageName,
                $originalName,
                $mimeType,
                $byteSize,
                $user->id,
            );
        } catch (\Throwable $e) {
            $this->storage->delete($storageName);

            throw $e;
        }

        return $this->serialize($attachment);
    }

    /** @return array{path: string, mime_type: string, byte_size: int, original_name: string} */
    public function open(User $user, int $attachmentId): array
    {
        $attachment = $this->attachments->findById($attachmentId);
        if ($attachment === null) {
            throw new NotFoundException('Anhang nicht gefunden.');
        }

        // Zugriff nur über die Seite: Wer sie nicht sehen darf, bekommt auch
        // den Anhang nicht - der Token allein genügt nicht.
        $this->pages->find($user, (int) $attachment['page_id']);

        $path = $this->storage->path((string) $attachment['storage_name']);
        if ($path === null) {
            throw new NotFoundException('Die Datei ist nicht mehr vorhanden.');
        }

        return [
            'path' => $path,
            'mime_type' => (string) $attachment['mime_type'],
            'byte_size' => (int) $attachment['byte_size'],
            'original_name' => (string) $attachment['original_name'],
        ];
    }

    /**
     * Speichernamen der Dateianhänge einer Seite - für das endgültige Löschen
     * der Seite, damit keine verwaisten Dateien zurückbleiben.
     *
     * @return string[]
     */
    public function storageNamesForPage(User $user, int $pageId): array
    {
        $page = $this->pages->findOwned($user, $pageId);

        return $this->attachments->storageNamesForPage((int) $page['id']);
    }

    /** @param string[] $storageNames */
    public function deleteStoredFiles(array $storageNames): void
    {
        foreach ($storageNames as $storageName) {
            $this->storage->delete($storageName);
        }
    }

    public function delete(User $user, int $attachmentId): void
    {
        $attachment = $this->attachments->findById($attachmentId);
        if ($attachment === null) {
            throw new NotFoundException('Anhang nicht gefunden.');
        }

        $pageId = (int) $attachment['page_id'];
        $this->pages->find($user, $pageId);
        $this->pages->assertCanWrite($user, $pageId);

        $this->attachments->delete($attachmentId);
        $this->storage->delete((string) $attachment['storage_name']);
    }

    /**
     * Anhänge und eingebettete Bilder teilen sich das Speicherkontingent des
     * Seiteneigentümers (FR-ADM-06).
     */
    private function assertQuota(int $pageId, int $additionalBytes): void
    {
        $quotaMb = $this->images->quotaMbForPageOwner($pageId)
            ?? ($this->settings->getInt(SettingsRepository::DEFAULT_STORAGE_QUOTA_MB, $this->fallbackQuotaMb)
                ?? $this->fallbackQuotaMb);

        if ($quotaMb <= 0) {
            return;
        }

        $used = $this->images->usedBytesForPageOwner($pageId) + $this->attachments->usedBytesForPageOwner($pageId);
        if ($used + $additionalBytes > $quotaMb * 1024 * 1024) {
            $usedMb = round($used / (1024 * 1024), 1);

            throw new ValidationException(
                "Das Speicherkontingent von {$quotaMb} MB ist erschöpft (belegt: {$usedMb} MB)."
            );
        }
    }

    /**
     * Dateinamen auf einen anzeigbaren Rest reduzieren. Pfadanteile fliegen
     * raus, damit der Name später gefahrlos im Content-Disposition-Header und
     * in der Oberfläche stehen kann.
     */
    private function sanitizeName(?string $name): string
    {
        $name = trim((string) $name);
        $name = str_replace(['\\', '/'], '', $name);
        $name = preg_replace('/[\x00-\x1F\x7F"]+/u', '', $name) ?? '';
        $name = trim($name);

        if ($name === '') {
            return 'anhang';
        }

        return mb_strlen($name) > 150 ? mb_substr($name, 0, 150) : $name;
    }

    /**
     * @param array<string, mixed> $attachment
     * @return array<string, mixed>
     */
    private function serialize(array $attachment): array
    {
        return [
            'id' => (int) $attachment['id'],
            'name' => (string) $attachment['original_name'],
            'mime_type' => (string) $attachment['mime_type'],
            'byte_size' => (int) $attachment['byte_size'],
            'is_pdf' => $attachment['mime_type'] === 'application/pdf',
            'url' => '/api/page-attachments/' . (int) $attachment['id'],
            'created_at' => $attachment['created_at'],
            'created_by_name' => $attachment['created_by_name'] ?? null,
        ];
    }
}
