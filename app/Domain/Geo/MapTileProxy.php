<?php

declare(strict_types=1);

namespace App\Domain\Geo;

use App\Support\ValidationException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Liefert Kartenkacheln für die Standortauswahl (FR-NOTE-27) - **serverseitig**
 * abgeholt, damit die Kartenkachel niemals direkt vom Browser des Nutzers beim
 * Kartendienst angefragt wird. Das schont sowohl die IP-Adresse des Nutzers
 * (dieselbe Überlegung wie bei der Adresssuche, {@see ReverseGeocoder}) als
 * auch die Content-Security-Policy: Da die Anwendung selbst ausliefert, bleibt
 * `img-src 'self'` unverändert.
 *
 * Kacheln werden auf dem Datenträger zwischengespeichert - das hält die
 * Anfragen beim Kartendienst in einem für dessen Nutzungsbedingungen
 * vertretbaren Rahmen (OpenStreetMap-Kacheln erwarten Zwischenspeicherung statt
 * wiederholter Abrufe derselben Kachel).
 */
final class MapTileProxy
{
    public const DEFAULT_URL_TEMPLATE = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

    private const MIN_ZOOM = 0;
    private const MAX_ZOOM = 19;
    private const CACHE_TTL_SECONDS = 30 * 24 * 3600;
    private const TIMEOUT_SECONDS = 8;
    private const USER_AGENT = 'nasmutNotes';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $cachePath,
        private readonly string $urlTemplate = self::DEFAULT_URL_TEMPLATE,
        private readonly string $appUrl = '',
        private readonly ?ClientInterface $http = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->urlTemplate !== '';
    }

    /** @return array{bytes: string, content_type: string} */
    public function fetch(int $z, int $x, int $y): array
    {
        if (!$this->isEnabled()) {
            throw new ValidationException('Kartenkacheln sind nicht verfügbar.');
        }
        $this->assertValidTile($z, $x, $y);

        $cacheFile = $this->cacheFile($z, $x, $y);
        $cached = $this->readCache($cacheFile);
        if ($cached !== null) {
            return $cached;
        }

        $client = $this->http ?? new Client(['timeout' => self::TIMEOUT_SECONDS, 'connect_timeout' => 3]);
        $url = str_replace(['{z}', '{x}', '{y}'], [(string) $z, (string) $x, (string) $y], $this->urlTemplate);

        try {
            $response = $client->request('GET', $url, [
                'http_errors' => false,
                'headers' => [
                    'User-Agent' => self::USER_AGENT . ($this->appUrl !== '' ? " ({$this->appUrl})" : ''),
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->info('Kartenkachel nicht erreichbar', ['message' => $e->getMessage()]);

            throw new ValidationException('Die Kartenkachel konnte nicht geladen werden.');
        }

        if ($response->getStatusCode() !== 200) {
            throw new ValidationException('Die Kartenkachel konnte nicht geladen werden.');
        }

        $bytes = (string) $response->getBody();
        $contentType = $response->getHeaderLine('Content-Type') ?: 'image/png';
        $this->writeCache($cacheFile, $contentType, $bytes);

        return ['bytes' => $bytes, 'content_type' => $contentType];
    }

    private function assertValidTile(int $z, int $x, int $y): void
    {
        if ($z < self::MIN_ZOOM || $z > self::MAX_ZOOM) {
            throw new ValidationException('Ungültige Zoomstufe.');
        }
        $limit = 2 ** $z;
        if ($x < 0 || $x >= $limit || $y < 0 || $y >= $limit) {
            throw new ValidationException('Ungültige Kachelkoordinate.');
        }
    }

    private function cacheFile(int $z, int $x, int $y): string
    {
        return rtrim($this->cachePath, '/') . "/{$z}/{$x}/{$y}.tile";
    }

    /** @return array{bytes: string, content_type: string}|null */
    private function readCache(string $file): ?array
    {
        if (!is_file($file) || (time() - filemtime($file)) >= self::CACHE_TTL_SECONDS) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !is_string($decoded['content_type'] ?? null) || !is_string($decoded['bytes'] ?? null)) {
            return null;
        }
        $bytes = base64_decode($decoded['bytes'], true);
        if ($bytes === false) {
            return null;
        }

        return ['bytes' => $bytes, 'content_type' => $decoded['content_type']];
    }

    private function writeCache(string $file, string $contentType, string $bytes): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            return;
        }

        $payload = json_encode(['content_type' => $contentType, 'bytes' => base64_encode($bytes)]);
        if ($payload === false) {
            return;
        }

        $temporary = $file . '.tmp-' . bin2hex(random_bytes(6));
        if (@file_put_contents($temporary, $payload, LOCK_EX) === strlen($payload)) {
            @rename($temporary, $file);
        } else {
            @unlink($temporary);
        }
    }
}
