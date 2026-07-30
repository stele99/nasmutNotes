<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Löst Vite-Einstiegspunkte zu HTML-Tags auf.
 * Entwicklung: Verweis auf den Vite-Dev-Server (HMR).
 * Produktion: Auflösung über das Manifest in public/build/.vite/manifest.json (Content-Hash-Dateinamen, NFR-PERF-07).
 */
final class Vite
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $manifest = null;

    public function __construct(
        private readonly string $publicPath,
        private readonly bool $devMode,
        private readonly string $devServerUrl,
    ) {
    }

    public function tags(string $entry, ?string $nonce = null): string
    {
        if ($this->devMode) {
            return $this->devTags($entry, $nonce);
        }

        return $this->prodTags($entry, $nonce);
    }

    private function devTags(string $entry, ?string $nonce): string
    {
        $nonceAttr = $nonce !== null ? " nonce=\"{$nonce}\"" : '';

        return
            "<script type=\"module\" data-cfasync=\"false\" src=\"{$this->devServerUrl}/build/@vite/client\"{$nonceAttr}></script>\n" .
            "<script type=\"module\" data-cfasync=\"false\" src=\"{$this->devServerUrl}/build/resources/js/{$entry}\"{$nonceAttr}></script>\n";
    }

    private function prodTags(string $entry, ?string $nonce): string
    {
        $manifest = $this->loadManifest();
        $key = "resources/js/{$entry}";
        $item = $manifest[$key] ?? null;

        if ($item === null) {
            return '';
        }

        $nonceAttr = $nonce !== null ? " nonce=\"{$nonce}\"" : '';
        $html = '';

        foreach ($this->cssFiles($manifest, $key) as $cssFile) {
            $html .= "<link rel=\"stylesheet\" href=\"/build/{$cssFile}\">\n";
        }

        $file = $item['file'];
        $html .= "<script type=\"module\" data-cfasync=\"false\" src=\"/build/{$file}\"{$nonceAttr}></script>\n";

        return $html;
    }

    /**
     * Vite kann gemeinsam genutztes CSS in einen importierten JS-Chunk
     * auslagern. Das Entry selbst nennt dann nur noch sein eigenes CSS; ohne
     * rekursive Auflösung würde die Produktion die Hauptstyles nicht laden.
     *
     * @param array<string, array<string, mixed>> $manifest
     * @return list<string>
     */
    private function cssFiles(array $manifest, string $key): array
    {
        $visited = [];
        $files = [];
        $collect = function (string $itemKey) use (&$collect, &$files, &$visited, $manifest): void {
            if (isset($visited[$itemKey])) {
                return;
            }
            $visited[$itemKey] = true;
            $item = $manifest[$itemKey] ?? null;
            if (!is_array($item)) {
                return;
            }

            foreach ($item['imports'] ?? [] as $import) {
                if (is_string($import)) {
                    $collect($import);
                }
            }
            foreach ($item['css'] ?? [] as $file) {
                if (is_string($file) && !in_array($file, $files, true)) {
                    $files[] = $file;
                }
            }
        };
        $collect($key);

        return $files;
    }

    /** @return array<string, array<string, mixed>> */
    private function loadManifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $path = $this->publicPath . '/build/.vite/manifest.json';
        if (!is_file($path)) {
            return $this->manifest = [];
        }

        $json = file_get_contents($path);
        $decoded = $json !== false ? json_decode($json, true) : null;

        return $this->manifest = is_array($decoded) ? $decoded : [];
    }
}
