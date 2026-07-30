<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Vite;
use PHPUnit\Framework\TestCase;

final class ViteTest extends TestCase
{
    private string $publicPath;

    protected function setUp(): void
    {
        $this->publicPath = sys_get_temp_dir() . '/vite-test-' . bin2hex(random_bytes(8));
        mkdir($this->publicPath . '/build/.vite', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->publicPath . '/build/.vite/manifest.json');
        @rmdir($this->publicPath . '/build/.vite');
        @rmdir($this->publicPath . '/build');
        @rmdir($this->publicPath);
    }

    public function testProductionTagsIncludeCssFromImportedChunks(): void
    {
        file_put_contents($this->publicPath . '/build/.vite/manifest.json', json_encode([
            '_shared.js' => [
                'file' => 'assets/shared.js',
                'css' => ['assets/main.css'],
            ],
            'resources/js/app.js' => [
                'file' => 'assets/app.js',
                'imports' => ['_shared.js'],
                'css' => ['assets/leaflet.css', 'assets/main.css'],
            ],
        ], JSON_THROW_ON_ERROR));

        $tags = (new Vite($this->publicPath, false, ''))->tags('app.js', 'nonce-value');

        self::assertSame(1, substr_count($tags, 'assets/main.css'));
        self::assertStringContainsString('<link rel="stylesheet" href="/build/assets/main.css">', $tags);
        self::assertStringContainsString('<link rel="stylesheet" href="/build/assets/leaflet.css">', $tags);
        self::assertStringContainsString(
            '<script type="module" data-cfasync="false" src="/build/assets/app.js" nonce="nonce-value"></script>',
            $tags,
        );
    }
}
