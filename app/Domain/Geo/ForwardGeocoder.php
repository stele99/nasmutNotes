<?php

declare(strict_types=1);

namespace App\Domain\Geo;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/** Sucht serverseitig Koordinaten zu einer Adresse. */
final class ForwardGeocoder
{
    public const DEFAULT_URL = 'https://nominatim.openstreetmap.org/search';

    private const USER_AGENT = 'nasmutNotes';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $endpoint = self::DEFAULT_URL,
        private readonly string $appUrl = '',
        private readonly string $language = 'de',
        private readonly ?ClientInterface $http = null,
    ) {
    }

    /** @return array<int, array{lat: float, lon: float, label: string}> */
    public function search(string $query): array
    {
        if ($this->endpoint === '') {
            return [];
        }

        $client = $this->http ?? new Client([
            'timeout' => 5,
            'connect_timeout' => 3,
            'http_errors' => false,
        ]);

        try {
            $response = $client->request('GET', $this->endpoint, [
                'http_errors' => false,
                'query' => [
                    'format' => 'jsonv2',
                    'q' => $query,
                    'limit' => 5,
                    'addressdetails' => 1,
                ],
                'headers' => [
                    'User-Agent' => self::USER_AGENT . ($this->appUrl !== '' ? " ({$this->appUrl})" : ''),
                    'Accept-Language' => $this->language !== '' ? $this->language : 'de',
                ],
            ]);
            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $payload = json_decode((string) $response->getBody(), true);
            if (!is_array($payload)) {
                return [];
            }

            $results = [];
            foreach ($payload as $item) {
                if (!is_array($item) || !is_numeric($item['lat'] ?? null) || !is_numeric($item['lon'] ?? null)) {
                    continue;
                }
                $lat = (float) $item['lat'];
                $lon = (float) $item['lon'];
                $label = is_string($item['display_name'] ?? null) ? trim($item['display_name']) : '';
                if (abs($lat) > 90 || abs($lon) > 180 || $label === '') {
                    continue;
                }
                $results[] = [
                    'lat' => $lat,
                    'lon' => $lon,
                    'label' => mb_strlen($label) > 300 ? mb_substr($label, 0, 299) . '…' : $label,
                ];
            }

            return array_slice($results, 0, 5);
        } catch (\Throwable $e) {
            $this->logger->info('Ortssuche fehlgeschlagen', ['message' => $e->getMessage()]);

            return [];
        }
    }
}
