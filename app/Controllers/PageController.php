<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\PageService;
use App\Repositories\AuditLogRepository;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use App\Support\RequestIp;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PageController
{
    public function __construct(
        private readonly PageService $pages,
        private readonly AuditLogRepository $auditLog,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $sort = is_string($params['sort'] ?? null) ? $params['sort'] : 'updated';
        $type = isset($params['type']) && in_array($params['type'], ['note', 'task'], true) ? $params['type'] : null;
        $trashed = ($params['trashed'] ?? '0') === '1';

        $pages = $this->pages->list(CurrentUser::require($request), $sort, $type, $trashed);

        return JsonResponse::json($response, ['pages' => array_map([$this, 'serialize'], $pages)]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $page = $this->pages->create(
            CurrentUser::require($request),
            (string) ($body['type'] ?? ''),
            (string) ($body['title'] ?? ''),
            isset($body['icon']) ? (string) $body['icon'] : null,
        );

        return JsonResponse::json($response, $this->serialize($page), 201);
    }

    /** @param array<string, string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $page = $this->pages->update(CurrentUser::require($request), (int) $args['id'], $body);

        return JsonResponse::json($response, $this->serialize($page));
    }

    /** @param array<string, string> $args */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $pageId = (int) $args['id'];
        $this->pages->softDelete($user, $pageId);
        $this->auditLog->log($user->id, 'page_deleted', 'page', $pageId, RequestIp::hash($request));

        return $response->withStatus(204);
    }

    /** @param array<string, string> $args */
    public function restore(Request $request, Response $response, array $args): Response
    {
        $this->pages->restore(CurrentUser::require($request), (int) $args['id']);

        return $response->withStatus(204);
    }

    /** @param array<string, string> $args */
    public function purge(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $pageId = (int) $args['id'];
        $this->pages->purge($user, $pageId);
        $this->auditLog->log($user->id, 'page_purged', 'page', $pageId, RequestIp::hash($request));

        return $response->withStatus(204);
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function serialize(array $page): array
    {
        return [
            'id' => (int) $page['id'],
            'type' => $page['type'],
            'title' => $page['title'],
            'icon' => $page['icon'],
            'is_favorite' => ((int) $page['is_favorite']) === 1,
            'sort_order' => (int) $page['sort_order'],
            'default_view' => $page['default_view'],
            'deleted_at' => $page['deleted_at'],
            'created_at' => $page['created_at'],
            'updated_at' => $page['updated_at'],
            'is_shared' => ($page['is_shared'] ?? false) === true,
            'share_permission' => $page['share_permission'] ?? null,
            'can_edit' => ($page['can_edit'] ?? true) === true,
        ];
    }
}
