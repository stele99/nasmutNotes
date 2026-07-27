<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Domain\InviteService;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use App\Support\Renderer;
use App\Support\RequestIp;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class InviteAdminController
{
    public function __construct(
        private readonly InviteService $invites,
        private readonly Renderer $renderer,
    ) {
    }

    public function page(Request $request, Response $response): Response
    {
        $html = $this->renderer->page($request, 'admin/invites', [], 'Admin · Invites');
        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function index(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);

        return JsonResponse::json($response, ['invites' => $this->invites->listFor($user, true)]);
    }

    public function store(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $invite = $this->invites->create($user, $body, RequestIp::hash($request));

        return JsonResponse::json($response, $invite, 201);
    }

    /** @param array<string, string> $args */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $this->invites->revoke($user, (int) ($args['id'] ?? 0), RequestIp::hash($request), true);

        return $response->withStatus(204);
    }
}
