<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\NotebookService;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class NotebookController
{
    public function __construct(private readonly NotebookService $notebooks)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        return JsonResponse::json($response, ['notebooks' => array_map([$this, 'serialize'], $this->notebooks->list(CurrentUser::require($request)))]);
    }

    public function store(Request $request, Response $response): Response
    {
        $notebook = $this->notebooks->create(CurrentUser::require($request), (array) ($request->getParsedBody() ?? []));

        return JsonResponse::json($response, $this->serialize($notebook), 201);
    }

    /** @param array<string, string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        $notebook = $this->notebooks->update(CurrentUser::require($request), (int) $args['id'], (array) ($request->getParsedBody() ?? []));

        return JsonResponse::json($response, $this->serialize($notebook));
    }

    /** @param array<string, string> $args */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->notebooks->delete(CurrentUser::require($request), (int) $args['id']);

        return $response->withStatus(204);
    }

    /** @param array<string, mixed> $notebook
     * @return array<string, mixed> */
    private function serialize(array $notebook): array
    {
        return [
            'id' => (int) $notebook['id'],
            'name' => $notebook['name'],
            'color' => $notebook['color'],
            'icon' => $notebook['icon'],
            'sort_order' => (int) $notebook['sort_order'],
            'is_hidden' => ((int) ($notebook['is_hidden'] ?? 0)) === 1,
            'page_count' => (int) $notebook['page_count'],
            'created_at' => $notebook['created_at'],
            'updated_at' => $notebook['updated_at'],
        ];
    }
}
