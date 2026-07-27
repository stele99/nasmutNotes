<?php

declare(strict_types=1);

namespace App\Support;

final class UploadStorage
{
    private string $basePath;

    public function __construct(string $rootPath, string $configuredPath)
    {
        $this->basePath = str_starts_with($configuredPath, '/')
            ? rtrim($configuredPath, '/')
            : rtrim($rootPath, '/') . '/' . trim($configuredPath, '/');
    }

    public function isWritable(): bool
    {
        return is_dir($this->basePath) && is_writable($this->basePath);
    }

    public function writeImage(int $pageId, string $bytes, string $extension): string
    {
        $directory = $this->basePath . '/notes/' . $pageId;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Das Upload-Verzeichnis konnte nicht angelegt werden.');
        }

        $filename = bin2hex(random_bytes(32)) . '.' . $extension;
        $storageName = 'notes/' . $pageId . '/' . $filename;
        $path = $directory . '/' . $filename;
        $temporaryPath = $path . '.tmp-' . bin2hex(random_bytes(8));

        if (file_put_contents($temporaryPath, $bytes, LOCK_EX) !== strlen($bytes)) {
            @unlink($temporaryPath);
            throw new \RuntimeException('Das Bild konnte nicht gespeichert werden.');
        }
        @chmod($temporaryPath, 0640);
        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new \RuntimeException('Das Bild konnte nicht aktiviert werden.');
        }

        return $storageName;
    }

    /**
     * Dateianhang einer Seite. Bewusst ohne sprechende Endung abgelegt: Der
     * Originalname und der MIME-Typ stehen in der Datenbank, und eine .bin-Datei
     * kann vom Webserver nicht versehentlich ausgeführt oder interpretiert
     * werden (FR-NOTE-18).
     */
    public function writeFile(int $pageId, string $bytes): string
    {
        $directory = $this->basePath . '/files/' . $pageId;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Das Upload-Verzeichnis konnte nicht angelegt werden.');
        }

        $filename = bin2hex(random_bytes(32)) . '.bin';
        $storageName = 'files/' . $pageId . '/' . $filename;
        $path = $directory . '/' . $filename;
        $temporaryPath = $path . '.tmp-' . bin2hex(random_bytes(8));

        if (file_put_contents($temporaryPath, $bytes, LOCK_EX) !== strlen($bytes)) {
            @unlink($temporaryPath);

            throw new \RuntimeException('Der Anhang konnte nicht gespeichert werden.');
        }
        @chmod($temporaryPath, 0640);
        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);

            throw new \RuntimeException('Der Anhang konnte nicht aktiviert werden.');
        }

        return $storageName;
    }

    public function path(string $storageName): ?string
    {
        $isImage = preg_match('#^notes/[1-9][0-9]*/[a-f0-9]{64}\.(png|jpg|webp)$#', $storageName) === 1;
        $isFile = preg_match('#^files/[1-9][0-9]*/[a-f0-9]{64}\.bin$#', $storageName) === 1;
        if (!$isImage && !$isFile) {
            return null;
        }

        $path = $this->basePath . '/' . $storageName;

        return is_file($path) ? $path : null;
    }

    public function delete(string $storageName): void
    {
        $path = $this->path($storageName);
        if ($path !== null) {
            @unlink($path);
        }
    }

    public function replace(string $storageName, string $bytes): void
    {
        $path = $this->path($storageName);
        if ($path === null) {
            throw new \RuntimeException('Die Bilddatei wurde nicht gefunden.');
        }

        $temporaryPath = $path . '.tmp-' . bin2hex(random_bytes(8));
        if (file_put_contents($temporaryPath, $bytes, LOCK_EX) !== strlen($bytes)) {
            @unlink($temporaryPath);
            throw new \RuntimeException('Das komprimierte Bild konnte nicht gespeichert werden.');
        }
        @chmod($temporaryPath, 0640);
        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new \RuntimeException('Das komprimierte Bild konnte nicht aktiviert werden.');
        }
    }
}
