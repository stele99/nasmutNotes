<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Geo\MapTileProxy;
use App\Support\CurrentUser;
use App\Support\RateLimiter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * Liefert Kartenkacheln für die Standortauswahl aus, geholt über
 * {@see MapTileProxy} - nie direkt vom Browser des Nutzers (FR-NOTE-27).
 */
final class MapTileController
{
    public function __construct(
        private readonly MapTileProxy $tiles,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        // Ein Kartenausschnitt lädt schnell mehrere Dutzend Kacheln; das Limit
        // erlaubt normales Schwenken/Zoomen, bremst aber automatisiertes Abgrasen.
        $user = CurrentUser::require($request);
        if (!$this->rateLimiter->attempt("map-tile:{$user->id}", 600, 300)) {
            return $response->withStatus(429);
        }

        $tile = $this->tiles->fetch((int) $args['z'], (int) $args['x'], (int) $args['y']);

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Die Kartenkachel konnte nicht ausgeliefert werden.');
        }
        fwrite($stream, $tile['bytes']);
        rewind($stream);

        return $response
            ->withBody(new Stream($stream))
            ->withHeader('Content-Type', $tile['content_type'])
            ->withHeader('Cache-Control', 'public, max-age=604800, immutable');
    }
}
