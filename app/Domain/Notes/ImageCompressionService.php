<?php

declare(strict_types=1);

namespace App\Domain\Notes;

use App\Domain\PageService;
use App\Domain\User;
use App\Repositories\NoteAttachmentRepository;
use App\Support\NotFoundException;
use App\Support\UploadStorage;
use App\Support\ValidationException;

final class ImageCompressionService
{
    private const MAX_WIDTHS = [
        'original' => null,
        'screen' => 1960,
        'medium' => 1024,
        'small' => 800,
    ];

    public function __construct(
        private readonly PageService $pages,
        private readonly NoteAttachmentRepository $attachments,
        private readonly UploadStorage $storage,
    ) {
    }

    /**
     * @return array{images: int, compressed: int, skipped: int, before_bytes: int, after_bytes: int, saved_bytes: int}
     */
    public function compress(User $user, int $pageId, int $quality, string $size): array
    {
        $page = $this->pages->findOwned($user, $pageId);
        if ($page['type'] !== 'note') {
            throw new NotFoundException('Diese Seite ist keine Notizseite.');
        }
        if ($page['deleted_at'] !== null) {
            throw new ValidationException('Bilder einer gelöschten Seite können nicht komprimiert werden.');
        }
        $this->validateOptions($quality, $size);

        return $this->compressRows($this->attachments->listForPage((int) $page['id']), $quality, $size);
    }

    /**
     * @return array{images: int, compressed: int, skipped: int, before_bytes: int, after_bytes: int, saved_bytes: int}
     */
    public function compressForUser(int $userId, int $quality = 82, string $size = 'screen'): array
    {
        $this->validateOptions($quality, $size);

        return $this->compressRows($this->attachments->listForUser($userId), $quality, $size);
    }

    private function validateOptions(int $quality, string $size): void
    {
        if ($quality < 40 || $quality > 95) {
            throw new ValidationException('Die Bildqualität muss zwischen 40 und 95 liegen.');
        }
        if (!array_key_exists($size, self::MAX_WIDTHS)) {
            throw new ValidationException('Ungültige Bildgröße.');
        }
        if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('Die PHP-Erweiterung GD ist für die Bildkompression erforderlich.');
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{images: int, compressed: int, skipped: int, before_bytes: int, after_bytes: int, saved_bytes: int}
     */
    private function compressRows(array $rows, int $quality, string $size): array
    {
        $beforeBytes = 0;
        $afterBytes = 0;
        $compressed = 0;
        $skipped = 0;

        foreach ($rows as $attachment) {
            $path = $this->storage->path((string) $attachment['storage_name']);
            if ($path === null) {
                ++$skipped;
                continue;
            }
            $original = file_get_contents($path);
            if (!is_string($original) || $original === '') {
                ++$skipped;
                continue;
            }

            $result = $this->compressBytes(
                $original,
                (string) $attachment['mime_type'],
                $quality,
                self::MAX_WIDTHS[$size],
            );
            $beforeBytes += strlen($original);
            $afterBytes += strlen($result['bytes']);

            if ($result['bytes'] === $original) {
                ++$skipped;
                continue;
            }

            $this->storage->replace((string) $attachment['storage_name'], $result['bytes']);
            try {
                $this->attachments->updateImageMetadata(
                    (int) $attachment['id'],
                    strlen($result['bytes']),
                    $result['width'],
                    $result['height'],
                );
            } catch (\Throwable $exception) {
                $this->storage->replace((string) $attachment['storage_name'], $original);
                throw $exception;
            }
            ++$compressed;
        }

        return [
            'images' => count($rows),
            'compressed' => $compressed,
            'skipped' => $skipped,
            'before_bytes' => $beforeBytes,
            'after_bytes' => $afterBytes,
            'saved_bytes' => max(0, $beforeBytes - $afterBytes),
        ];
    }

    /** @return array{bytes: string, width: int, height: int} */
    private function compressBytes(string $bytes, string $mimeType, int $quality, ?int $maxWidth): array
    {
        $source = @imagecreatefromstring($bytes);
        if (!$source instanceof \GdImage) {
            throw new ValidationException('Ein Bild ist beschädigt und konnte nicht verarbeitet werden.');
        }

        try {
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            $width = max(1, $maxWidth !== null ? min($sourceWidth, $maxWidth) : $sourceWidth);
            $height = max(1, (int) round($sourceHeight * ($width / $sourceWidth)));
            $target = $source;

            if ($width !== $sourceWidth) {
                $target = imagecreatetruecolor($width, $height);
                if (!$target instanceof \GdImage) {
                    throw new \RuntimeException('Das Zielbild konnte nicht erzeugt werden.');
                }
                if ($mimeType === 'image/png' || $mimeType === 'image/webp') {
                    imagealphablending($target, false);
                    imagesavealpha($target, true);
                    $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
                    if ($transparent === false || !imagefill($target, 0, 0, $transparent)) {
                        imagedestroy($target);
                        throw new \RuntimeException('Die Bildtransparenz konnte nicht vorbereitet werden.');
                    }
                }
                if (!imagecopyresampled(
                    $target,
                    $source,
                    0,
                    0,
                    0,
                    0,
                    $width,
                    $height,
                    $sourceWidth,
                    $sourceHeight,
                )) {
                    imagedestroy($target);
                    throw new \RuntimeException('Das Bild konnte nicht skaliert werden.');
                }
            }

            ob_start();
            $written = match ($mimeType) {
                'image/jpeg' => $this->writeJpeg($target, $quality),
                'image/png' => imagepng($target, null, 9),
                'image/webp' => imagewebp($target, null, $quality),
                default => false,
            };
            $compressed = ob_get_clean();
            if ($target !== $source) {
                imagedestroy($target);
            }
            if (!$written || !is_string($compressed) || $compressed === '') {
                throw new \RuntimeException('Das Bild konnte nicht komprimiert werden.');
            }

            if ($width === $sourceWidth && strlen($compressed) >= strlen($bytes)) {
                return ['bytes' => $bytes, 'width' => $sourceWidth, 'height' => $sourceHeight];
            }

            return ['bytes' => $compressed, 'width' => $width, 'height' => $height];
        } finally {
            imagedestroy($source);
        }
    }

    private function writeJpeg(\GdImage $image, int $quality): bool
    {
        imageinterlace($image, true);

        return imagejpeg($image, null, $quality);
    }
}
