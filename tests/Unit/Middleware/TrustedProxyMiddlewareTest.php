<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Middleware\TrustedProxyMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class TrustedProxyMiddlewareTest extends TestCase
{
    private ?ServerRequestInterface $seen = null;

    public function testForwardedHttpsBecomesTheRequestScheme(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://notes.example/app')
            ->withHeader('X-Forwarded-Proto', 'https');

        $response = (new TrustedProxyMiddleware(true))->process($request, $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($this->seen);
        self::assertSame('https', $this->seen->getUri()->getScheme());
    }

    public function testForwardedHttpIsRedirectedInProduction(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://notes.example/app?x=1')
            ->withHeader('X-Forwarded-Proto', 'http');

        $response = (new TrustedProxyMiddleware(true))->process($request, $this->handler());

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('https://notes.example/app?x=1', $response->getHeaderLine('Location'));
        self::assertNull($this->seen);
    }

    public function testNoRedirectWithoutHeaderOrForHealthCheck(): void
    {
        $middleware = new TrustedProxyMiddleware(true);
        $factory = new ServerRequestFactory();

        $plain = $middleware->process($factory->createServerRequest('GET', 'http://app:8080/app'), $this->handler());
        $health = $middleware->process(
            $factory->createServerRequest('GET', 'http://notes.example/health')->withHeader('X-Forwarded-Proto', 'http'),
            $this->handler(),
        );

        self::assertSame(200, $plain->getStatusCode());
        self::assertSame(200, $health->getStatusCode());
    }

    public function testNoRedirectOutsideProduction(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost:8080/api/x')
            ->withHeader('X-Forwarded-Proto', 'http');

        self::assertSame(200, (new TrustedProxyMiddleware(false))->process($request, $this->handler())->getStatusCode());
    }

    private function handler(): RequestHandlerInterface
    {
        return new class ($this) implements RequestHandlerInterface {
            public function __construct(private readonly TrustedProxyMiddlewareTest $test)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->test->record($request);

                return new Response(200);
            }
        };
    }

    public function record(ServerRequestInterface $request): void
    {
        $this->seen = $request;
    }
}
