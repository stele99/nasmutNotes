<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly bool $isProduction,
        private readonly ?string $viteDevServerUrl = null,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $nonce = base64_encode(random_bytes(16));
        $request = $request->withAttribute('csp_nonce', $nonce);

        $response = $handler->handle($request);

        [$viteOrigin, $viteSocketOrigin] = $this->viteOrigins();
        $viteSource = $viteOrigin !== null ? " {$viteOrigin}" : '';
        $viteConnectSources = $viteOrigin !== null ? " {$viteOrigin} {$viteSocketOrigin}" : '';

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'{$viteSource}",
            "style-src 'self' 'unsafe-inline'{$viteSource}",
            "img-src 'self' data:",
            "font-src 'self'{$viteSource}",
            "connect-src 'self'{$viteConnectSources}",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);

        $response = $response
            ->withHeader('Content-Security-Policy', $csp)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        if ($this->isProduction && $request->getUri()->getScheme() === 'https') {
            $response = $response->withHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        return $response;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function viteOrigins(): array
    {
        if ($this->isProduction || $this->viteDevServerUrl === null) {
            return [null, null];
        }

        $parts = parse_url($this->viteDevServerUrl);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        if (!is_string($scheme) || !in_array($scheme, ['http', 'https'], true) || !is_string($host)) {
            return [null, null];
        }

        $port = is_array($parts) && isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $origin = "{$scheme}://{$host}{$port}";
        $socketScheme = $scheme === 'https' ? 'wss' : 'ws';

        return [$origin, "{$socketScheme}://{$host}{$port}"];
    }
}
