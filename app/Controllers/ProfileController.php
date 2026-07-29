<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\UserRepository;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use App\Support\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ProfileController
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function update(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $user = CurrentUser::require($request);
        $result = [];

        if (array_key_exists('nearby_search_radius_km', $body)) {
            $radius = $body['nearby_search_radius_km'];
            if (!is_int($radius) && !is_float($radius) && !is_string($radius)) {
                throw new ValidationException('Ungültiger Suchradius.');
            }
            if (!is_numeric($radius)) {
                throw new ValidationException('Ungültiger Suchradius.');
            }

            $radiusKm = (float) $radius;
            if (!is_finite($radiusKm) || $radiusKm < 0.1 || $radiusKm > 50.0) {
                throw new ValidationException('Der Suchradius muss zwischen 0,1 und 50 km liegen.');
            }
            $radiusKm = round($radiusKm, 1);
            $this->users->updateNearbySearchRadius($user->id, $radiusKm);
            $result['nearby_search_radius_km'] = $radiusKm;
        }

        if (($body['info_acknowledged'] ?? null) === true) {
            $this->users->acknowledgeInfo($user->id);
            $result['info_acknowledged'] = true;
        }

        if (array_key_exists('location_capture_mode', $body)) {
            $mode = $body['location_capture_mode'];
            if (!is_string($mode) || !in_array($mode, ['manual', 'auto'], true)) {
                throw new ValidationException('Ungültige Standort-Einstellung.');
            }
            $this->users->updateLocationCaptureMode($user->id, $mode);
            $result['location_capture_mode'] = $mode;
        }

        if ($result === []) {
            throw new ValidationException('Keine gültige Profileinstellung übermittelt.');
        }

        return JsonResponse::json($response, $result);
    }
}
