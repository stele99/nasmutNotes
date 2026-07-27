<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Middleware\SecurityHeadersMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SecurityHeadersMiddlewareTest extends TestCase
{
    /**
     * PDF-Anhänge werden im überlagerten Betrachter in einem gleichnamigen
     * iframe gezeigt. Mit `frame-ancestors 'none'` verweigert der Browser das -
     * der Betrachter bliebe leer (FR-NOTE-20).
     */
    public function testPdfResponsesMayBeFramedBySameOrigin(): void
    {
        $csp = $this->cspFor('application/pdf');

        self::assertStringContainsString("frame-ancestors 'self'", $csp);
        self::assertStringNotContainsString("frame-ancestors 'none'", $csp);
    }

    public function testHtmlResponsesStayUnframeable(): void
    {
        $csp = $this->cspFor('text/html; charset=utf-8');

        self::assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    public function testJsonResponsesStayUnframeable(): void
    {
        self::assertStringContainsString("frame-ancestors 'none'", $this->cspFor('application/json'));
    }

    public function testBaselineDirectivesRemainInPlace(): void
    {
        $csp = $this->cspFor('application/pdf');

        foreach (["default-src 'self'", "base-uri 'self'", "form-action 'self'", "script-src 'self'"] as $directive) {
            self::assertStringContainsString($directive, $csp);
        }
    }

    private function cspFor(string $contentType): string
    {
        $middleware = new SecurityHeadersMiddleware(true, null);
        $request = new ServerRequestFactory()->createServerRequest('GET', 'https://example.test/x');

        $handler = new class ($contentType) implements RequestHandlerInterface {
            public function __construct(private readonly string $contentType)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new ResponseFactory()->createResponse()
                    ->withHeader('Content-Type', $this->contentType);
            }
        };

        return $middleware->process($request, $handler)->getHeaderLine('Content-Security-Policy');
    }
}
