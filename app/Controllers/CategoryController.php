<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\TaskBoardService;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CategoryController
{
    public function __construct(private readonly TaskBoardService $board)
    {
    }

    /** @param array<string, string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $category = $this->board->updateCategory(CurrentUser::require($request), (int) $args['id'], $body);

        return JsonResponse::json($response, [
            'id' => (int) $category['id'],
            'name' => $category['name'],
            'color' => $category['color'],
            'position' => (int) $category['position'],
            'wip_limit' => $category['wip_limit'] !== null ? (int) $category['wip_limit'] : null,
        ]);
    }

    /** @param array<string, string> $args */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $params = $request->getQueryParams();
        $moveTo = isset($params['move_to']) ? (int) $params['move_to'] : null;
        $cascade = ($params['cascade'] ?? '0') === '1';

        $this->board->deleteCategory(CurrentUser::require($request), (int) $args['id'], $moveTo, $cascade);

        return $response->withStatus(204);
    }
}
