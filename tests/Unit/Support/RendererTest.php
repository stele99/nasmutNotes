<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Renderer;
use App\Support\View;
use App\Support\Vite;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Seitenwechsel innerhalb der App holen nur Titel und <main>
 * (`?_partial=main`); ein normaler Aufruf liefert weiter das ganze Dokument.
 */
final class RendererTest extends TestCase
{
    private string $views;

    protected function setUp(): void
    {
        $this->views = sys_get_temp_dir() . '/renderer-test-' . bin2hex(random_bytes(6));
        mkdir($this->views);
        copy(dirname(__DIR__, 3) . '/resources/views/partial_layout.php', $this->views . '/partial_layout.php');
        file_put_contents($this->views . '/layout.php', '<html><head><title><?= e($title) ?></title></head><body><?= $content ?></body></html>');
        file_put_contents($this->views . '/page.php', '<?php if (empty($partial)): ?><aside>Hülle</aside><?php endif; ?><main>Inhalt</main>');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->views . '/*') ?: []);
        rmdir($this->views);
    }

    public function testFragmentContainsOnlyTitleAndMain(): void
    {
        $html = $this->renderer()->fragment($this->request('/app/page/1?_partial=main'), 'page', [], 'Baustelle <Müller>');

        self::assertStringContainsString('<title>Baustelle &lt;Müller&gt;</title>', $html);
        self::assertStringContainsString('<main>Inhalt</main>', $html);
        self::assertStringNotContainsString('Hülle', $html);
        self::assertStringNotContainsString('<html', $html);
    }

    public function testFullPageKeepsTheShell(): void
    {
        $html = $this->renderer()->page($this->request('/app/page/1'), 'page', [], 'Notiz');

        self::assertStringContainsString('<aside>Hülle</aside>', $html);
        self::assertStringContainsString('<html>', $html);
    }

    public function testFragmentIsOnlyRequestedExplicitly(): void
    {
        self::assertTrue(Renderer::wantsFragment($this->request('/app?_partial=main')));
        self::assertFalse(Renderer::wantsFragment($this->request('/app')));
        self::assertFalse(Renderer::wantsFragment($this->request('/app?_partial=1')));
    }

    private function renderer(): Renderer
    {
        return new Renderer(new View($this->views), new Vite($this->views, false, ''));
    }

    private function request(string $uri): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $uri);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return $request->withQueryParams($query);
    }
}
