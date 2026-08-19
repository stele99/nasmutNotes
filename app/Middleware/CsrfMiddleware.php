<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Domain\SessionService;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Double-Submit-Cookie-CSRF-Schutz + Origin-Prüfung (NFR-SEC-02).
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const COOKIE_NAME = 'csrf_token';
    private const HEADER_NAME = 'X-CSRF-Token';
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly string $appUrl,
        private readonly bool $secureCookies,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $cookies = $request->getCookieParams();
        $cookieToken = $cookies[self::COOKIE_NAME] ?? null;

        if (!is_string($cookieToken) || strlen($cookieToken) < 32) {
            $cookieToken = bin2hex(random_bytes(32));
            $mustSetCookie = true;
        } else {
            $mustSetCookie = false;
        }

        $request = $request->withAttribute('csrf_token', $cookieToken);

        if (!in_array($request->getMethod(), self::SAFE_METHODS, true) && !$this->isBearerOnlyRequest($request)) {
            $error = $this->validate($request, $cookieToken);
            if ($error !== null) {
                $response = new ResponseFactory()->createResponse(403);

                return JsonResponse::error($response, 'CSRF_FAILED', $error, 403);
            }
        }

        $response = $handler->handle($request);

        if ($mustSetCookie) {
            $response = $response->withAddedHeader('Set-Cookie', $this->cookieHeader($cookieToken));
        }

        return $response;
    }

    /**
     * Das Double-Submit-Verfahren schützt cookie-authentifizierte Anfragen vor
     * fremden Origins: Eine fremde Seite kann das Cookie "ambient" mitschicken,
     * einen Bearer-Token im Authorization-Header dagegen nicht. Trägt eine
     * Anfrage einen solchen Header und kein Session-Cookie (Automations-Token,
     * FR-NVOICE), greift CSRF-Schutz also gar nicht erst - alle
     * cookie-authentifizierten Routen bleiben davon unberührt.
     */
    private function isBearerOnlyRequest(Request $request): bool
    {
        $cookies = $request->getCookieParams();
        $hasSessionCookie = isset($cookies[SessionService::COOKIE_NAME]) && $cookies[SessionService::COOKIE_NAME] !== '';

        return !$hasSessionCookie && str_starts_with($request->getHeaderLine('Authorization'), 'Bearer ');
    }

    private function validate(Request $request, string $cookieToken): ?string
    {
        $header = $request->getHeaderLine(self::HEADER_NAME);
        if ($header === '' || !hash_equals($cookieToken, $header)) {
            return 'Ungültiges oder fehlendes CSRF-Token.';
        }

        $origin = $request->getHeaderLine('Origin');
        $expectedHost = parse_url($this->appUrl, PHP_URL_HOST);

        if ($origin !== '') {
            $originHost = parse_url($origin, PHP_URL_HOST);
            if ($originHost === null || $originHost !== $expectedHost) {
                return 'Ungültiger Origin-Header.';
            }

            return null;
        }

        $referer = $request->getHeaderLine('Referer');
        if ($referer !== '') {
            $refererHost = parse_url($referer, PHP_URL_HOST);
            if ($refererHost === null || $refererHost !== $expectedHost) {
                return 'Ungültiger Referer-Header.';
            }
        }

        return null;
    }

    private function cookieHeader(string $token): string
    {
        // HttpOnly ist unbedenklich: das Frontend liest das Token aus dem
        // <meta name="csrf-token">-Tag, nicht aus dem Cookie (document.cookie).
        $attrs = [
            self::COOKIE_NAME . '=' . $token,
            'Path=/',
            'SameSite=Lax',
            'HttpOnly',
        ];
        if ($this->secureCookies) {
            $attrs[] = 'Secure';
        }

        return implode('; ', $attrs);
    }
}
