<?php

declare(strict_types=1);

namespace App\Domain\Geo;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Sucht zu Koordinaten die Anschrift (FR-NOTE-26). Vorgabe ist Nominatim von
 * OpenStreetMap.
 *
 * Der Aufruf geschieht bewusst **serverseitig**: So bleibt die IP-Adresse des
 * Nutzers beim Kartendienst unbekannt, und die Anwendung kommt ohne gelockerte
 * CSP aus. Die gefundene Anschrift wird an der Seite gespeichert, damit das
 * Anzeigen einer Notiz keine weitere Anfrage auslöst.
 *
 * Scheitert die Suche, bleibt es bei den Koordinaten - der Ort selbst geht
 * dadurch nie verloren.
 */
final class ReverseGeocoder
{
    public const DEFAULT_URL = 'https://nominatim.openstreetmap.org/reverse';

    private const TIMEOUT_SECONDS = 5;

    /** Nominatim verlangt eine identifizierende Kennung mit Kontaktmöglichkeit. */
    private const USER_AGENT = 'nasmutNotes';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $endpoint = self::DEFAULT_URL,
        private readonly string $appUrl = '',
        private readonly string $language = 'de',
        private readonly ?ClientInterface $http = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->endpoint !== '';
    }

    public function lookup(float $lat, float $lon): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $client = $this->http ?? new Client([
            'timeout' => self::TIMEOUT_SECONDS,
            'connect_timeout' => 3,
            'http_errors' => false,
        ]);

        try {
            $response = $client->request('GET', $this->endpoint, [
                'http_errors' => false,
                'query' => [
                    'format' => 'jsonv2',
                    'lat' => $lat,
                    'lon' => $lon,
                    'zoom' => 18,
                    'addressdetails' => 1,
                ],
                'headers' => [
                    'User-Agent' => self::USER_AGENT . ($this->appUrl !== '' ? " ({$this->appUrl})" : ''),
                    'Accept-Language' => $this->language !== '' ? $this->language : 'de',
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $payload = json_decode((string) $response->getBody(), true);

            return is_array($payload) ? $this->label($payload) : null;
        } catch (\Throwable $e) {
            // Eine nicht erreichbare Adresssuche darf das Anlegen einer Notiz
            // nicht scheitern lassen.
            $this->logger->info('Adresssuche fehlgeschlagen', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /** @param array<string, mixed> $payload */
    private function label(array $payload): ?string
    {
        $address = is_array($payload['address'] ?? null) ? $payload['address'] : [];

        $street = trim(implode(' ', array_filter([
            $this->text($address, 'road') ?? $this->text($address, 'pedestrian') ?? $this->text($address, 'footway'),
            $this->text($address, 'house_number'),
        ])));

        $city = $this->text($address, 'city')
            ?? $this->text($address, 'town')
            ?? $this->text($address, 'village')
            ?? $this->text($address, 'municipality')
            ?? $this->text($address, 'suburb');

        $place = trim(implode(' ', array_filter([$this->text($address, 'postcode'), $city])));

        $parts = array_values(array_filter([$street, $place, $this->text($address, 'country')]));
        $label = $parts !== [] ? implode(', ', $parts) : $this->text($payload, 'display_name');

        if ($label === null || $label === '') {
            return null;
        }

        return mb_strlen($label) > 200 ? mb_substr($label, 0, 199) . '…' : $label;
    }

    /** @param array<string, mixed> $source */
    private function text(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
