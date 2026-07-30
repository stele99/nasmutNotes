<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Geo\NearbySearchService;
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
        private readonly NearbySearchService $nearby,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $query = trim((string) ($params['q'] ?? ''));
        if ($query === '' || mb_strlen($query) > 100) {
            throw new ValidationException('Der Suchbegriff muss 1-100 Zeichen lang sein.');
        }

        // Die Seitenleiste sucht in ihrer Sammlung, die Übersicht überall.
        $collection = is_string($params['collection'] ?? null) ? $params['collection'] : null;
        $notebookId = isset($params['notebook_id']) && ctype_digit((string) $params['notebook_id'])
            ? (int) $params['notebook_id']
            : null;

        $user = CurrentUser::require($request);
        $results = $this->search->search(
            $this->pages->workspaceIdFor($user),
            $user->id,
            $query,
            $collection,
            $notebookId,
        );

        return JsonResponse::json($response, ['pages' => $results]);
    }

    /**
     * Umkreissuche über Seiten mit Ort (FR-NOTE-27) - eine Seite mit mehreren
     * passenden Logbuch-Einträgen erscheint trotzdem nur einmal.
     */
    public function nearby(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        if (!isset($params['lat'], $params['lon']) || !is_numeric($params['lat']) || !is_numeric($params['lon'])) {
            throw new ValidationException('Bitte zuerst einen Standort wählen.');
        }
        $radiusKm = isset($params['radius_km']) && is_numeric($params['radius_km'])
            ? (float) $params['radius_km']
            : NearbySearchService::DEFAULT_RADIUS_KM;

        $user = CurrentUser::require($request);
        $results = $this->nearby->search($user, (float) $params['lat'], (float) $params['lon'], $radiusKm);

        return JsonResponse::json($response, [
            'results' => array_map([self::class, 'serializeNearby'], $results),
        ]);
    }

    /**
     * @param array{page_id: int, page_type: string, title: string, label: ?string, updated_at: ?string, distance_km: float} $item
     * @return array<string, mixed>
     */
    private static function serializeNearby(array $item): array
    {
        return [
            'page_id' => $item['page_id'],
            'page_type' => $item['page_type'],
            'title' => $item['title'],
            'label' => $item['label'],
            'updated_at' => $item['updated_at'],
            'distance_km' => round($item['distance_km'], 3),
        ];
    }
}
