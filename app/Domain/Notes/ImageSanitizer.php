<?php

declare(strict_types=1);

namespace App\Domain\Notes;

use App\Support\ValidationException;

/**
 * Codiert hochgeladene Bilder mit GD neu, bevor sie gespeichert werden.
 *
 * - Ausgeliefert wird nie das Original: Das Neuschreiben verwirft EXIF/XMP
 *   (Standort, Kameradaten), Kommentare und alles, was hinter oder in den
 *   Bilddaten versteckt war (Polyglot-Dateien).
 * - Die EXIF-Ausrichtung wird vorher angewendet, damit Hochkantfotos nach dem
 *   Entfernen der Metadaten nicht quer erscheinen.
 * - Vor dem Dekodieren wird der Speicherbedarf abgeschätzt: GD hält jedes
 *   Pixel mit 4 Byte, eine 90°-Drehung braucht ein zweites Bild. Reicht das
 *   PHP-Limit nicht, wird es für diese Anfrage angehoben, aber nie über
 *   MEMORY_CEILING (Container-Limit 256 MB).
 *
 * Die Prüfung von Größe, Typ (getimagesize) und Pixelzahl übernimmt der
 * Aufrufer (AttachmentService) vor dem Aufruf.
 */
final class ImageSanitizer
{
    /** Obergrenze für memory_limit während der Bildverarbeitung. */
    public const MEMORY_CEILING = 200 * 1024 * 1024;

    private const JPEG_QUALITY = 90;
    private const WEBP_QUALITY = 90;
    private const PNG_COMPRESSION = 6;

    /** @return array{bytes: string, width: int, height: int} */
    public function sanitize(string $bytes, string $mimeType, int $width, int $height): array
    {
        if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('Die PHP-Erweiterung GD ist für Bild-Uploads erforderlich.');
        }

        $orientation = $mimeType === 'image/jpeg' ? $this->exifOrientation($bytes) : 1;
        $needsSecondBuffer = $orientation >= 5 && $orientation <= 8;
        $this->reserveMemory($width, $height, strlen($bytes), $needsSecondBuffer);

        $image = @imagecreatefromstring($bytes);
        if (!$image instanceof \GdImage) {
            throw new ValidationException('Die Bilddatei ist beschädigt oder ungültig.');
        }

        try {
            $image = $this->applyOrientation($image, $orientation);

            if ($mimeType === 'image/png' || $mimeType === 'image/webp') {
                imagealphablending($image, false);
                imagesavealpha($image, true);
            }
            if ($mimeType === 'image/jpeg') {
                imageinterlace($image, true);
            }

            ob_start();
            try {
                $written = match ($mimeType) {
                    'image/jpeg' => imagejpeg($image, null, self::JPEG_QUALITY),
                    'image/png' => imagepng($image, null, self::PNG_COMPRESSION),
                    'image/webp' => imagewebp($image, null, self::WEBP_QUALITY),
                    default => false,
                };
            } finally {
                $encoded = ob_get_clean();
            }

            if (!$written || !is_string($encoded) || $encoded === '') {
                throw new \RuntimeException('Das Bild konnte nicht neu gespeichert werden.');
            }

            return ['bytes' => $encoded, 'width' => imagesx($image), 'height' => imagesy($image)];
        } finally {
            imagedestroy($image);
        }
    }

    /** EXIF-Orientierung 1-8; 1, wenn nicht lesbar oder exif fehlt. */
    private function exifOrientation(string $bytes): int
    {
        if (!function_exists('exif_read_data')) {
            return 1;
        }

        $stream = fopen('php://memory', 'r+b');
        if ($stream === false) {
            return 1;
        }

        try {
            fwrite($stream, $bytes);
            rewind($stream);
            $exif = @exif_read_data($stream, 'IFD0');
        } catch (\Throwable) {
            return 1;
        } finally {
            fclose($stream);
        }

        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    private function applyOrientation(\GdImage $image, int $orientation): \GdImage
    {
        // Spiegelungen und 180° gehen ohne zweites Bild (imageflip arbeitet in place).
        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);

                return $image;
            case 3:
                imageflip($image, IMG_FLIP_BOTH);

                return $image;
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);

                return $image;
            case 5:
                imageflip($image, IMG_FLIP_HORIZONTAL);

                return $this->rotate($image, 90);
            case 6:
                return $this->rotate($image, -90);
            case 7:
                imageflip($image, IMG_FLIP_HORIZONTAL);

                return $this->rotate($image, -90);
            case 8:
                return $this->rotate($image, 90);
            default:
                return $image;
        }
    }

    /** imagerotate dreht gegen den Uhrzeigersinn. */
    private function rotate(\GdImage $image, int $degrees): \GdImage
    {
        $rotated = imagerotate($image, $degrees, 0);
        if (!$rotated instanceof \GdImage) {
            imagedestroy($image);

            throw new \RuntimeException('Das Bild konnte nicht gedreht werden.');
        }
        imagedestroy($image);

        return $rotated;
    }

    private function reserveMemory(int $width, int $height, int $byteSize, bool $secondBuffer): void
    {
        $pixelBytes = $width * $height * 4;
        $needed = memory_get_usage(true)
            + $pixelBytes * ($secondBuffer ? 2 : 1)
            + $byteSize * 2 // kodierte Ausgabe im Puffer
            + 16 * 1024 * 1024; // Dekoder, Zeilenpuffer, Reserve

        if ($needed > self::MEMORY_CEILING) {
            throw new ValidationException('Das Bild ist zu groß, um es zu verarbeiten. Bitte verkleinern.');
        }

        $limit = self::bytesFromIni((string) ini_get('memory_limit'));
        if ($limit !== -1 && $limit < $needed) {
            ini_set('memory_limit', (string) $needed);
        }
    }

    private static function bytesFromIni(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
