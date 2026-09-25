<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\SessionService;
use App\Repositories\AuditLogRepository;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use App\Support\RequestIp;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Eigene Anmeldungen einsehen und beenden - etwa nach dem Verlust eines
 * Firmenhandys, ohne auf den Administrator warten zu müssen.
 */
final class SessionController
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly AuditLogRepository $auditLog,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);

        return JsonResponse::json($response, [
            'sessions' => $this->sessions->listForUser($user->id, $this->currentToken($request)),
        ]);
    }

    /** @param array<string, string> $args */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $sessionId = (int) ($args['id'] ?? 0);
        $this->sessions->revokeForUser($user->id, $sessionId);
        $this->auditLog->log($user->id, 'session_revoked', 'session', $sessionId, RequestIp::hash($request));

        return $response->withStatus(204);
    }

    public function destroyOthers(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);
        $revoked = $this->sessions->revokeOthers($user->id, $this->currentToken($request));
        $this->auditLog->log($user->id, 'sessions_revoked_others', 'user', $user->id, RequestIp::hash($request), [
            'sessions' => $revoked,
        ]);

        return JsonResponse::json($response, ['revoked' => $revoked]);
    }

    private function currentToken(Request $request): ?string
    {
        $token = $request->getCookieParams()[SessionService::COOKIE_NAME] ?? null;

        return is_string($token) ? $token : null;
    }
}
