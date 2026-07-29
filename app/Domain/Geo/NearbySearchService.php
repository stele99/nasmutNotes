<?php

declare(strict_types=1);

namespace App\Domain\Geo;

use App\Domain\PageService;
use App\Domain\User;
use App\Repositories\LogRepository;
use App\Repositories\PageRepository;
use App\Support\ValidationException;

/**
 * Umkreissuche über alle Seiten mit Aufnahmeort (FR-NOTE-27): Zu einem
 * gewählten Mittelpunkt werden alle Treffer innerhalb eines Radius gemeldet,
 * nach Entfernung sortiert.
 *
 * Gesucht wird nach **Seiten**, nicht nach einzelnen Logbuch-Einträgen: Ein
 * Logbuch kann mehrere Einträge mit Ortsspalten-Werten im Umkreis haben, dann
 * erscheint die Seite trotzdem nur einmal - mit der Entfernung zu ihrem
 * nächstgelegenen Treffer (eigener Seitenort oder der nächste Eintrag).
 *
 * Bewusst auf den eigenen Workspace beschränkt - anders als die
 * Textsuche (SearchRepository) bezieht sie keine geteilten Seiten ein, weil
 * ein Ort dort ohnehin dem Eigentümer gehört (FR-NOTE-25) und die Umkreissuche
 * ein persönliches Werkzeug ist.
 */
final class NearbySearchService
{
    private const EARTH_RADIUS_KM = 6371.0;
    public const DEFAULT_RADIUS_KM = 1.0;
    public const MIN_RADIUS_KM = 0.05;
    public const MAX_RADIUS_KM = 500.0;
    private const MAX_RESULTS = 200;

    public function __construct(
        private readonly PageService $pages,
        private readonly PageRepository $pageRepository,
        private readonly LogRepository $log,
    ) {
    }

    /**
     * @return array<int, array{
     *     page_id: int,
     *     page_type: string,
     *     title: string,
     *     label: ?string,
     *     updated_at: ?string,
     *     distance_km: float
     * }>
     */
    public function search(User $user, float $lat, float $lon, float $radiusKm): array
    {
        if (abs($lat) > 90 || abs($lon) > 180) {
            throw new ValidationException('Ungültige Koordinaten.');
        }
        $radiusKm = min(self::MAX_RADIUS_KM, max(self::MIN_RADIUS_KM, $radiusKm));

        $workspaceId = $this->pages->workspaceIdFor($user);

        // Je Seite nur der nächstgelegene Treffer - eine Seite mit mehreren
        // passenden Logbuch-Einträgen soll trotzdem nur einmal erscheinen.
        $nearestByPage = [];

        foreach ($this->pageRepository->pagesWithLocation($workspaceId) as $page) {
            $pageId = (int) $page['id'];
            $nearestByPage[$pageId] = [
                'page_id' => $pageId,
                'page_type' => (string) $page['type'],
                'title' => (string) $page['title'],
                'label' => $page['location_label'] !== null ? (string) $page['location_label'] : null,
                'updated_at' => (string) $page['updated_at'],
                'distance_km' => self::distanceKm($lat, $lon, (float) $page['location_lat'], (float) $page['location_lon']),
            ];
        }

        foreach ($this->log->locatedValuesForWorkspace($workspaceId) as $value) {
            $pageId = (int) $value['page_id'];
            $distance = self::distanceKm($lat, $lon, (float) $value['value_lat'], (float) $value['value_lon']);
            if (isset($nearestByPage[$pageId]) && $nearestByPage[$pageId]['distance_km'] <= $distance) {
                continue;
            }
            $nearestByPage[$pageId] = [
                'page_id' => $pageId,
                'page_type' => 'log',
                'title' => (string) $value['page_title'],
                'label' => $value['value_text'] !== null ? (string) $value['value_text'] : null,
                'updated_at' => null,
                'distance_km' => $distance,
            ];
        }

        $results = array_values(array_filter(
            $nearestByPage,
            static fn (array $result): bool => $result['distance_km'] <= $radiusKm,
        ));

        usort($results, static fn (array $left, array $right): int => $left['distance_km'] <=> $right['distance_km']);

        return array_slice($results, 0, self::MAX_RESULTS);
    }

    /** Haversine-Formel: hinreichend genau für einen Umkreis von Metern bis einigen hundert Kilometern. */
    public static function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLon = deg2rad($lon2 - $lon1);

        $a = sin($deltaLat / 2) ** 2 + cos($lat1Rad) * cos($lat2Rad) * sin($deltaLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_KM * $c;
    }
}
