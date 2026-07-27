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

final class AttachmentService
{
    private const MIME_EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];
    private const MAX_PIXELS = 40_000_000;

    public function __construct(
        private readonly PageService $pages,
        private readonly NoteAttachmentRepository $attachments,
        private readonly UploadStorage $storage,
        private readonly int $maxUploadMb,
        private readonly ?SettingsRepository $settings = null,
        private readonly int $defaultQuotaMb = 0,
        private readonly ?PageAttachmentRepository $files = null,
    ) {
    }

    /**
     * Wirksames Kontingent des Seiteneigentümers in MB. 0 oder kleiner
     * bedeutet: keine Begrenzung.
     */
    private function quotaMbForPage(int $pageId): int
    {
        $personal = $this->attachments->quotaMbForPageOwner($pageId);
        if ($personal !== null) {
            return $personal;
        }

        return $this->settings?->getInt(SettingsRepository::DEFAULT_STORAGE_QUOTA_MB, $this->defaultQuotaMb)
            ?? $this->defaultQuotaMb;
    }

    /** @return array{token: string, src: string, mime_type: string, width: int, height: int, byte_size: int} */
    public function upload(User $user, int $pageId, UploadedFileInterface $file): array
    {
        $page = $this->pages->find($user, $pageId);
        if ($page['type'] !== 'note') {
            throw new NotFoundException('Diese Seite ist keine Notizseite.');
        }
        $this->pages->assertCanWrite($user, $pageId);

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException('Das Bild konnte nicht hochgeladen werden.');
        }

        $maxBytes = max(1, $this->maxUploadMb) * 1024 * 1024;
        $reportedSize = $file->getSize();
        if ($reportedSize !== null && ($reportedSize < 1 || $reportedSize > $maxBytes)) {
            throw new ValidationException("Das Bild darf maximal {$this->maxUploadMb} MB groß sein.");
        }

        $stream = $file->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $bytes = $stream->getContents();
        $byteSize = strlen($bytes);
        if ($byteSize < 1 || $byteSize > $maxBytes) {
            throw new ValidationException("Das Bild darf maximal {$this->maxUploadMb} MB groß sein.");
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!is_string($mimeType) || !isset(self::MIME_EXTENSIONS[$mimeType])) {
            throw new ValidationException('Erlaubt sind ausschließlich PNG-, JPEG- und WebP-Bilder.');
        }

        $imageInfo = getimagesizefromstring($bytes);
        if ($imageInfo === false) {
            throw new ValidationException('Die Bilddatei ist beschädigt oder ungültig.');
        }
        $width = $imageInfo[0];
        $height = $imageInfo[1];
        $detectedMime = $imageInfo['mime'];
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS || $detectedMime !== $mimeType) {
            throw new ValidationException('Das Bild hat ungültige oder zu große Abmessungen.');
        }

        // Kontingentprüfung erst hier: Die tatsächliche Dateigröße steht erst
        // nach dem Einlesen fest, die gemeldete Größe ist nicht verbindlich.
        $quotaMb = $this->quotaMbForPage((int) $page['id']);
        if ($quotaMb > 0) {
            $quotaBytes = $quotaMb * 1024 * 1024;
            // Bilder und Dateianhänge teilen sich das Kontingent. Würde hier nur
            // der Bildspeicher zählen, ließe sich die Grenze über Dateianhänge
            // umgehen (FR-ADM-06).
            $usedBytes = $this->attachments->usedBytesForPageOwner((int) $page['id'])
                + ($this->files?->usedBytesForPageOwner((int) $page['id']) ?? 0);
            if ($usedBytes + $byteSize > $quotaBytes) {
                $usedMb = round($usedBytes / (1024 * 1024), 1);

                throw new ValidationException(
                    "Das Speicherkontingent von {$quotaMb} MB ist erschöpft (belegt: {$usedMb} MB). "
                    . 'Bitte nicht mehr benötigte Bilder entfernen.'
                );
            }
        }

        $token = bin2hex(random_bytes(32));
        $storageName = $this->storage->writeImage(
            (int) $page['id'],
            $bytes,
            self::MIME_EXTENSIONS[$mimeType],
        );

        try {
            $this->attachments->create(
                (int) $page['id'],
                hash('sha256', $token),
                $storageName,
                $this->safeOriginalName($file->getClientFilename()),
                $mimeType,
                $byteSize,
                $width,
                $height,
                $user->id,
            );
        } catch (\Throwable $e) {
            $this->storage->delete($storageName);
            throw $e;
        }

        return [
            'token' => $token,
            'src' => '/api/attachments/' . $token,
            'mime_type' => $mimeType,
            'width' => $width,
            'height' => $height,
            'byte_size' => $byteSize,
        ];
    }

    /** @return array{path: string, mime_type: string, byte_size: int} */
    public function open(User $user, string $token): array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw new NotFoundException('Bild nicht gefunden.');
        }

        $attachment = $this->attachments->findByTokenHash(hash('sha256', $token));
        if ($attachment === null) {
            throw new NotFoundException('Bild nicht gefunden.');
        }

        $this->pages->find($user, (int) $attachment['page_id']);
        $path = $this->storage->path((string) $attachment['storage_name']);
        if ($path === null) {
            throw new NotFoundException('Bilddatei nicht gefunden.');
        }

        return [
            'path' => $path,
            'mime_type' => (string) $attachment['mime_type'],
            'byte_size' => (int) $attachment['byte_size'],
        ];
    }

    /** @return string[] */
    public function storageNamesForOwnedPage(User $user, int $pageId): array
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

    private function safeOriginalName(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        return mb_substr(basename(str_replace("\0", '', trim($name))), 0, 255);
    }
}
