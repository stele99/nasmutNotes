<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\InviteRepository;
use App\Support\JsonResponse;
use App\Support\RateLimiter;
use App\Support\Renderer;
use App\Support\RequestIp;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\ResponseFactory;

final class InviteController
{
    public function __construct(
        private readonly InviteRepository $invites,
        private readonly Renderer $renderer,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    /** @param array<string, string> $args */
    public function accept(Request $request, Response $response, array $args): Response
    {
        $ipHash = RequestIp::hash($request);
        if (!$this->rateLimiter->attempt("invite:{$ipHash}", 20, 60)) {
            $response = new ResponseFactory()->createResponse(429);

            return JsonResponse::error($response, 'RATE_LIMITED', 'Zu viele Versuche. Bitte kurz warten.', 429);
        }

        $rawToken = (string) ($args['token'] ?? '');
        $invite = $this->invites->findByTokenHash(hash('sha256', $rawToken));

        if ($invite === null) {
            return $this->error($request, $response, 'Dieser Einladungslink ist ungültig.');
        }

        $status = InviteRepository::status($invite);
        $message = match ($status) {
            'revoked' => 'Dieser Einladungslink wurde widerrufen.',
            'expired' => 'Dieser Einladungslink ist abgelaufen.',
            'used' => 'Dieser Einladungslink wurde bereits vollständig verwendet.',
            default => null,
        };

        if ($message !== null) {
            return $this->error($request, $response, $message);
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', '/auth/google?invite=' . rawurlencode($rawToken));
    }

    private function error(Request $request, Response $response, string $message): Response
    {
        $html = $this->renderer->page($request, 'login', ['error' => $message], 'Einladung');
        $response->getBody()->write($html);

        return $response->withStatus(200)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
