<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Auth\DeviceTokenService;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use App\Support\RateLimiter;
use App\Support\RequestIp;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Automations-Token für NotesVoice (FR-NVOICE), verwaltet vom Nutzer selbst -
 * sichtbar und widerrufbar sind ausschließlich eigene Token.
 */
final class DeviceTokenController
{
    public function __construct(
        private readonly DeviceTokenService $tokens,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);

        return JsonResponse::json($response, ['device_tokens' => $this->tokens->listFor($user)]);
    }

    public function store(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);

        if (!$this->rateLimiter->attempt("device-token-create:{$user->id}", 10, 3600)) {
            return JsonResponse::error(
                $response,
                'RATE_LIMITED',
                'Zu viele Token in kurzer Zeit. Bitte später erneut versuchen.',
                429,
            );
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $result = $this->tokens->issue($user, (string) ($body['label'] ?? ''), RequestIp::hash($request));

        return JsonResponse::json($response, $result, 201);
    }

    /** @param array<string, string> $args */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $this->tokens->revoke($user, (int) ($args['id'] ?? 0), RequestIp::hash($request));

        return $response->withStatus(204);
    }
}
