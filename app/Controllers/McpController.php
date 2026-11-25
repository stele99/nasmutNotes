<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Mcp\McpServer;
use App\Domain\Mcp\McpTokenService;
use App\Support\JsonResponse;
use App\Support\RateLimiter;
use App\Support\RequestIp;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * MCP-Endpunkt `/mcp` (FR-MCP-04). Authentifiziert ausschließlich per
 * MCP-Token im Authorization-Header - ein Session-Cookie zählt hier nicht,
 * damit ein Browser-Tab nie unbemerkt als MCP-Client gilt.
 */
final class McpController
{
    private const MAX_BODY_BYTES = 2_000_000;
    /** Großzügig für Agenten, die viele kleine Schritte machen. */
    private const REQUESTS_PER_MINUTE = 240;

    public function __construct(
        private readonly McpTokenService $tokens,
        private readonly McpServer $server,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    public function handle(Request $request, Response $response): Response
    {
        $resolved = $this->authenticate($request);
        if ($resolved === null) {
            return JsonResponse::error(
                $response,
                'UNAUTHORIZED',
                'Gültiger MCP-Token erforderlich (Authorization: Bearer nmcp_…).',
                401,
            )->withHeader('WWW-Authenticate', 'Bearer realm="nasmut-notes-mcp"');
        }

        $tokenId = $resolved['token']->id;
        if (!$this->rateLimiter->attempt("mcp:{$tokenId}", self::REQUESTS_PER_MINUTE, 60)) {
            return JsonResponse::error(
                $response,
                'RATE_LIMITED',
                'Zu viele MCP-Anfragen. Bitte kurz warten.',
                429,
            )->withHeader('Retry-After', '60');
        }

        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $raw = $body->getContents();
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            return JsonResponse::error($response, 'PAYLOAD_TOO_LARGE', 'Die Anfrage ist zu groß.', 413);
        }

        try {
            $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return JsonResponse::json($response, McpServer::parseError(), 400);
        }

        $result = $this->server->handle(
            $payload,
            $resolved['token'],
            $resolved['user'],
            RequestIp::hash($request),
        );

        // Nur Benachrichtigungen: laut Spezifikation 202 ohne Körper.
        if ($result === null) {
            return $response->withStatus(202);
        }

        return JsonResponse::json($response, $result);
    }

    /**
     * Dieser Server bietet keinen SSE-Strom für Server-Nachrichten an und führt
     * keine Sitzungen - GET und DELETE sind daher laut Spezifikation 405.
     */
    public function methodNotAllowed(Request $request, Response $response): Response
    {
        return $response->withStatus(405)->withHeader('Allow', 'POST');
    }

    /** @return array{token: \App\Domain\Mcp\McpToken, user: \App\Domain\User}|null */
    private function authenticate(Request $request): ?array
    {
        $header = $request->getHeaderLine('Authorization');
        if ($header === '') {
            // Manche Apache/CGI-Konfigurationen reichen den Header nur über
            // die Umleitungsvariable weiter.
            $server = $request->getServerParams();
            $header = (string) ($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $match) !== 1) {
            return null;
        }

        return $this->tokens->resolve($match[1]);
    }
}
