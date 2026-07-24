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
            "<script type=\"module\" data-cfasync=\"false\" src=\"{$this->devServerUrl}/@vite/client\"{$nonceAttr}></script>\n" .
            "<script type=\"module\" data-cfasync=\"false\" src=\"{$this->devServerUrl}/resources/js/{$entry}\"{$nonceAttr}></script>\n";
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

        foreach ($item['css'] ?? [] as $cssFile) {
            $html .= "<link rel=\"stylesheet\" href=\"/build/{$cssFile}\">\n";
        }

        $file = $item['file'];
        $html .= "<script type=\"module\" data-cfasync=\"false\" src=\"/build/{$file}\"{$nonceAttr}></script>\n";

        return $html;
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
