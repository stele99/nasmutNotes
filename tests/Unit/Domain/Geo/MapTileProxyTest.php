<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Geo;

use App\Domain\Geo\MapTileProxy;
use App\Support\ValidationException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;

final class MapTileProxyTest extends TestCase
{
    private MockHandler $http;
    private string $cachePath;
    private int $requestCount = 0;

    protected function setUp(): void
    {
        $this->http = new MockHandler();
        $this->cachePath = sys_get_temp_dir() . '/tile-test-' . bin2hex(random_bytes(4));
        $this->requestCount = 0;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->cachePath);
    }

    public function testFetchesATileFromTheConfiguredServer(): void
    {
        $this->http->append(new Response(200, ['Content-Type' => 'image/png'], 'PNG-BYTES'));

        $tile = $this->proxy()->fetch(3, 4, 2);

        self::assertSame('PNG-BYTES', $tile['bytes']);
        self::assertSame('image/png', $tile['content_type']);
        self::assertSame(1, $this->requestCount);
    }

    public function testSendsAnIdentifyingUserAgentAsRequiredByTileUsagePolicies(): void
    {
        $this->http->append(new Response(200, ['Content-Type' => 'image/png'], 'PNG-BYTES'));
        $stack = HandlerStack::create($this->http);
        $captured = null;
        $stack->push(Middleware::mapRequest(function (RequestInterface $request) use (&$captured): RequestInterface {
            $captured = $request;

            return $request;
        }));
        $proxy = new MapTileProxy(
            new NullLogger(),
            $this->cachePath,
            MapTileProxy::DEFAULT_URL_TEMPLATE,
            'https://notes.example.com',
            new Client(['handler' => $stack, 'http_errors' => false]),
        );

        $proxy->fetch(1, 0, 0);

        self::assertNotNull($captured);
        self::assertStringContainsString('nasmutNotes', $captured->getHeaderLine('User-Agent'));
        self::assertStringContainsString('https://notes.example.com', $captured->getHeaderLine('User-Agent'));
        self::assertStringContainsString('/1/0/0.png', (string) $captured->getUri());
    }

    public function testASecondRequestForTheSameTileComesFromTheCache(): void
    {
        $this->http->append(new Response(200, ['Content-Type' => 'image/png'], 'PNG-BYTES'));
        $proxy = $this->proxy();

        $first = $proxy->fetch(5, 1, 1);
        $second = $proxy->fetch(5, 1, 1);

        self::assertSame($first, $second);
        // Nur der erste Aufruf hat wirklich beim Kartendienst angefragt - die
        // Nutzungsbedingungen von OSM-Kacheln erwarten genau das.
        self::assertSame(1, $this->requestCount);
    }

    public function testRejectsCoordinatesOutsideTheTileGridForAGivenZoom(): void
    {
        $proxy = $this->proxy();

        $this->expectException(ValidationException::class);
        // Bei Zoomstufe 2 sind nur die Kacheln 0-3 gültig.
        $proxy->fetch(2, 4, 0);
    }

    public function testRejectsAnOutOfRangeZoomLevel(): void
    {
        $proxy = $this->proxy();

        $this->expectException(ValidationException::class);
        $proxy->fetch(25, 0, 0);
    }

    public function testAFailedUpstreamRequestSurfacesAsAValidationException(): void
    {
        $this->http->append(new Response(503, [], 'kaputt'));

        $this->expectException(ValidationException::class);
        $this->proxy()->fetch(1, 0, 0);
    }

    public function testADisabledProxyRefusesEveryRequest(): void
    {
        $proxy = new MapTileProxy(new NullLogger(), $this->cachePath, '');

        self::assertFalse($proxy->isEnabled());
        $this->expectException(ValidationException::class);
        $proxy->fetch(1, 0, 0);
    }

    private function proxy(): MapTileProxy
    {
        $stack = HandlerStack::create($this->http);
        $stack->push(Middleware::mapRequest(function (RequestInterface $request): RequestInterface {
            ++$this->requestCount;

            return $request;
        }));

        return new MapTileProxy(
            new NullLogger(),
            $this->cachePath,
            MapTileProxy::DEFAULT_URL_TEMPLATE,
            'https://notes.example.com',
            new Client(['handler' => $stack, 'http_errors' => false]),
        );
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
        }
        rmdir($path);
    }
}
