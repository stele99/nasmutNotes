<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\PageService;
use App\Repositories\SearchRepository;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use App\Support\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SearchController
{
    public function __construct(
        private readonly PageService $pages,
        private readonly SearchRepository $search,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $query = trim((string) ($request->getQueryParams()['q'] ?? ''));
        if ($query === '' || mb_strlen($query) > 100) {
            throw new ValidationException('Der Suchbegriff muss 1-100 Zeichen lang sein.');
        }

        $user = CurrentUser::require($request);
        $results = $this->search->search($this->pages->workspaceIdFor($user), $query);

        return JsonResponse::json($response, ['pages' => $results]);
    }
}
