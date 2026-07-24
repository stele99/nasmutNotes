<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\TaskBoardService;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class BoardController
{
    public function __construct(private readonly TaskBoardService $board)
    {
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $categories = $this->board->board(CurrentUser::require($request), (int) $args['id']);

        return JsonResponse::json($response, ['categories' => array_map([$this, 'serializeCategory'], $categories)]);
    }

    /** @param array<string, string> $args */
    public function createCategory(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $category = $this->board->createCategory(
            CurrentUser::require($request),
            (int) $args['id'],
            (string) ($body['name'] ?? ''),
            isset($body['color']) ? (string) $body['color'] : null,
            isset($body['wip_limit']) ? (int) $body['wip_limit'] : null,
        );

        return JsonResponse::json($response, $this->serializeCategory($category + ['tasks' => []]), 201);
    }

    /**
     * @param array<string, mixed> $category
     * @return array<string, mixed>
     */
    public function serializeCategory(array $category): array
    {
        return [
            'id' => (int) $category['id'],
            'name' => $category['name'],
            'color' => $category['color'],
            'position' => (int) $category['position'],
            'wip_limit' => $category['wip_limit'] !== null ? (int) $category['wip_limit'] : null,
            'tasks' => array_map([TaskController::class, 'serialize'], $category['tasks'] ?? []),
        ];
    }
}
