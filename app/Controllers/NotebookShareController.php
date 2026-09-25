<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\NotebookShareService;
use App\Repositories\AuditLogRepository;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use App\Support\RequestIp;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class NotebookShareController
{
    public function __construct(
        private readonly NotebookShareService $shares,
        private readonly AuditLogRepository $auditLog,
    ) {
    }

    /** @param array<string, string> $args */
    public function index(Request $request, Response $response, array $args): Response
    {
        $participants = $this->shares->listParticipants(CurrentUser::require($request), (int) $args['id']);

        return JsonResponse::json($response, ['participants' => array_map([$this, 'serialize'], $participants)]);
    }

    /** @param array<string, string> $args */
    public function store(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $participant = $this->shares->share(
            $user,
            (int) $args['id'],
            (string) ($body['email'] ?? ''),
            (string) ($body['permission'] ?? 'write'),
        );
        $this->auditLog->log($user->id, 'notebook_member_added', 'notebook', (int) $args['id'], RequestIp::hash($request), [
            'member_id' => $participant['id'],
            'permission' => $participant['permission'],
        ]);

        return JsonResponse::json($response, $this->serialize($participant), 201);
    }

    /** @param array<string, string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $permission = (string) ($body['permission'] ?? '');
        $this->shares->setPermission($user, (int) $args['id'], (int) $args['userId'], $permission);
        $this->auditLog->log($user->id, 'notebook_member_changed', 'notebook', (int) $args['id'], RequestIp::hash($request), [
            'member_id' => (int) $args['userId'],
            'permission' => $permission,
        ]);

        return $response->withStatus(204);
    }

    /** @param array<string, string> $args */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $this->shares->removeParticipant($user, (int) $args['id'], (int) $args['userId']);
        $this->auditLog->log($user->id, 'notebook_member_removed', 'notebook', (int) $args['id'], RequestIp::hash($request), [
            'member_id' => (int) $args['userId'],
        ]);

        return $response->withStatus(204);
    }

    /** @param array<string, string> $args */
    public function leave(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $this->shares->leave($user, (int) $args['id']);
        $this->auditLog->log($user->id, 'notebook_left', 'notebook', (int) $args['id'], RequestIp::hash($request));

        return $response->withStatus(204);
    }

    /**
     * @param array<string, mixed> $participant
     * @return array<string, mixed>
     */
    private function serialize(array $participant): array
    {
        return [
            'id' => (int) $participant['id'],
            'name' => (string) $participant['name'],
            'email' => (string) $participant['email'],
            'created_at' => $participant['created_at'],
            'permission' => ($participant['permission'] ?? 'write') === 'read' ? 'read' : 'write',
        ];
    }
}
