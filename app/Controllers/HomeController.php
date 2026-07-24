<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Renderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HomeController
{
    public function __construct(private readonly Renderer $renderer)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        if ($request->getAttribute('user') !== null) {
            return $response->withStatus(302)->withHeader('Location', '/app');
        }

        $error = match ($request->getQueryParams()['error'] ?? null) {
            'access_denied' => 'Die Anmeldung wurde abgebrochen.',
            default => null,
        };

        $html = $this->renderer->page($request, 'login', ['error' => $error], 'Anmeldung');
        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
