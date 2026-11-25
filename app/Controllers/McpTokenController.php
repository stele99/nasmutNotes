<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Mcp\McpTokenService;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use App\Support\RateLimiter;
use App\Support\RequestIp;
use App\Support\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Selbstverwaltung der MCP-Token (FR-MCP-01/02) und Schreibfreigabe einzelner
 * Seiten für die eigenen MCP-Clients (FR-MCP-03).
 */
final class McpTokenController
{
    public function __construct(
        private readonly McpTokenService $tokens,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);

        return JsonResponse::json($response, ['mcp_tokens' => $this->tokens->listFor($user)]);
    }

    public function store(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);

        if (!$this->rateLimiter->attempt("mcp-token-create:{$user->id}", 10, 3600)) {
            return JsonResponse::error(
                $response,
                'RATE_LIMITED',
                'Zu viele Token in kurzer Zeit. Bitte später erneut versuchen.',
                429,
            );
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $result = $this->tokens->issue(
            $user,
            is_string($body['label'] ?? null) ? $body['label'] : '',
            $body['write_mode'] ?? null,
            $body['can_create'] ?? null,
            RequestIp::hash($request),
        );

        // Die Antwort trägt den einzigen Klartext des Tokens: nirgends
        // zwischenspeichern (Browser, Proxy, CDN).
        return JsonResponse::json($response, $result, 201)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    /** @param array<string, string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $body = (array) ($request->getParsedBody() ?? []);

        return JsonResponse::json($response, $this->tokens->update(
            $user,
            (int) ($args['id'] ?? 0),
            $body,
            RequestIp::hash($request),
        ));
    }

    /** @param array<string, string> $args */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $this->tokens->revoke($user, (int) ($args['id'] ?? 0), RequestIp::hash($request));

        return $response->withStatus(204);
    }

    /** @param array<string, string> $args */
    public function pageAccess(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);

        return JsonResponse::json($response, $this->tokens->pageAccess($user, (int) ($args['id'] ?? 0)));
    }

    /** @param array<string, string> $args */
    public function updatePageAccess(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $body = (array) ($request->getParsedBody() ?? []);
        if (!is_bool($body['writable'] ?? null)) {
            throw new ValidationException('writable muss true oder false sein.');
        }

        return JsonResponse::json($response, $this->tokens->setPageWritable(
            $user,
            (int) ($args['id'] ?? 0),
            $body['writable'],
            RequestIp::hash($request),
        ));
    }

    /** Übersicht der Notizbücher samt MCP-Freigabe (Einstellungen › MCP-Zugang). */
    public function notebooks(Request $request, Response $response): Response
    {
        return JsonResponse::json($response, $this->tokens->notebookAccessList(CurrentUser::require($request)));
    }

    /** @param array<string, string> $args */
    public function updateNotebookAccess(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $body = (array) ($request->getParsedBody() ?? []);
        if (!is_bool($body['writable'] ?? null)) {
            throw new ValidationException('writable muss true oder false sein.');
        }

        return JsonResponse::json($response, $this->tokens->setNotebookWritable(
            $user,
            (int) ($args['id'] ?? 0),
            $body['writable'],
            RequestIp::hash($request),
        ));
    }
}
