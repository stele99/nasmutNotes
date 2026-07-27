<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\InviteService;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use App\Support\RateLimiter;
use App\Support\RequestIp;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Einladungen für angemeldete Nutzer (FR-INV-09). Sichtbar und widerrufbar
 * sind ausschließlich selbst erzeugte Einladungen.
 */
final class UserInviteController
{
    public function __construct(
        private readonly InviteService $invites,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);

        return JsonResponse::json($response, ['invites' => $this->invites->listFor($user, false)]);
    }

    public function store(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);

        // Ohne Bremse könnte ein einzelnes Konto den Zugang für beliebig viele
        // Fremde öffnen; 10 Einladungen pro Stunde reichen für den Normalfall.
        if (!$this->rateLimiter->attempt("invite-create:{$user->id}", 10, 3600)) {
            return JsonResponse::error(
                $response,
                'RATE_LIMITED',
                'Zu viele Einladungen in kurzer Zeit. Bitte später erneut versuchen.',
                429,
            );
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $invite = $this->invites->create($user, $body, RequestIp::hash($request));

        return JsonResponse::json($response, $invite, 201);
    }

    /** @param array<string, string> $args */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $this->invites->revoke($user, (int) ($args['id'] ?? 0), RequestIp::hash($request), false);

        return $response->withStatus(204);
    }
}
