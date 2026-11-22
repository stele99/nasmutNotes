<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

/**
 * HTTPS endet vor der Anwendung (Cloudflare); die App selbst sieht HTTP.
 *
 * - X-Forwarded-Proto: https setzt das Schema der Anfrage auf https, damit
 *   HSTS, Secure-Cookies und erzeugte URLs stimmen.
 * - Im Betrieb wird eine Anfrage, die laut Proxy per http kam, auf https
 *   umgeleitet (ersetzt die frühere .htaccess-Regel). Ohne den Header - etwa
 *   beim Health-Check des Containers - wird nicht umgeleitet, sonst entstünde
 *   eine Schleife oder ein fehlschlagender Check.
 */
final class TrustedProxyMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly bool $redirectToHttps)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $proto = self::forwardedProto($request);

        if ($proto === 'https' && $request->getUri()->getScheme() !== 'https') {
            $uri = $request->getUri()->withScheme('https');
            if ($uri->getPort() === 80) {
                $uri = $uri->withPort(null);
            }
            $request = $request->withUri($uri, true);
        }

        if (
            $this->redirectToHttps
            && $proto === 'http'
            && $request->getUri()->getPath() !== '/health'
        ) {
            $target = $request->getUri()->withScheme('https')->withPort(null);
            $method = strtoupper($request->getMethod());
            $status = $method === 'GET' || $method === 'HEAD' ? 301 : 308;

            return (new SlimResponse($status))->withHeader('Location', (string) $target);
        }

        return $handler->handle($request);
    }

    public static function forwardedProto(Request $request): ?string
    {
        $header = $request->getHeaderLine('X-Forwarded-Proto');
        if ($header === '') {
            return null;
        }
        // Bei mehreren Proxys steht das Schema des Clients vorne.
        $first = strtolower(trim(explode(',', $header)[0]));

        return in_array($first, ['http', 'https'], true) ? $first : null;
    }
}
