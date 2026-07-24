<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Repositories\AuditLogRepository;
use App\Repositories\InviteRepository;
use App\Support\CurrentUser;
use App\Support\Env;
use App\Support\JsonResponse;
use App\Support\Renderer;
use App\Support\RequestIp;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class InviteAdminController
{
    public function __construct(
        private readonly InviteRepository $invites,
        private readonly Renderer $renderer,
        private readonly AuditLogRepository $auditLog,
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
        $rows = array_map(static function (array $invite): array {
            return [
                'id' => (int) $invite['id'],
                'email' => $invite['email'],
                'note' => $invite['note'],
                'status' => InviteRepository::status($invite),
                'max_uses' => (int) $invite['max_uses'],
                'used_count' => (int) $invite['used_count'],
                'expires_at' => $invite['expires_at'],
                'created_at' => $invite['created_at'],
                'created_by_email' => $invite['created_by_email'],
            ];
        }, $this->invites->all());

        return JsonResponse::json($response, ['invites' => $rows]);
    }

    public function store(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);
        $body = (array) ($request->getParsedBody() ?? []);

        $email = isset($body['email']) && $body['email'] !== '' ? trim((string) $body['email']) : null;
        $note = isset($body['note']) && $body['note'] !== '' ? trim((string) $body['note']) : null;
        $maxUses = isset($body['max_uses']) ? max(1, (int) $body['max_uses']) : 1;
        $ttlDays = isset($body['ttl_days']) ? max(1, (int) $body['ttl_days']) : Env::int('INVITE_TTL_DAYS', 7);

        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return JsonResponse::error($response, 'VALIDATION_FAILED', 'Ungültige E-Mail-Adresse.', 422);
        }

        $rawToken = bin2hex(random_bytes(32));
        $expiresAt = gmdate('Y-m-d\TH:i:s.v\Z', time() + ($ttlDays * 86400));

        $id = $this->invites->create(
            hash('sha256', $rawToken),
            $email,
            $note,
            $user->id,
            $maxUses,
            $expiresAt,
        );

        $this->auditLog->log($user->id, 'invite_created', 'invite', $id, RequestIp::hash($request), [
            'email' => $email,
            'max_uses' => $maxUses,
        ]);

        $appUrl = rtrim(Env::get('APP_URL', '') ?? '', '/');

        return JsonResponse::json($response, [
            'id' => $id,
            'token' => $rawToken,
            'invite_url' => "{$appUrl}/invite/{$rawToken}",
            'expires_at' => $expiresAt,
        ], 201);
    }

    /** @param array<string, string> $args */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $id = (int) ($args['id'] ?? 0);
        $this->invites->revoke($id);
        $this->auditLog->log($user->id, 'invite_revoked', 'invite', $id, RequestIp::hash($request));

        return $response->withStatus(204);
    }
}
