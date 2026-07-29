<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Geo\ForwardGeocoder;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use App\Support\RateLimiter;
use App\Support\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GeocodeController
{
    public function __construct(
        private readonly ForwardGeocoder $geocoder,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    public function search(Request $request, Response $response): Response
    {
        $query = trim((string) ($request->getQueryParams()['q'] ?? ''));
        if (mb_strlen($query) < 2 || mb_strlen($query) > 100) {
            throw new ValidationException('Die Adresse muss 2-100 Zeichen lang sein.');
        }

        $user = CurrentUser::require($request);
        if (!$this->rateLimiter->attempt("geocode:{$user->id}", 30, 60)) {
            return JsonResponse::error($response, 'RATE_LIMITED', 'Zu viele Ortssuchen. Bitte kurz warten.', 429);
        }

        return JsonResponse::json($response, ['results' => $this->geocoder->search($query)]);
    }
}
