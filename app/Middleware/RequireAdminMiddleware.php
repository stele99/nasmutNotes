<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

final class RequireAdminMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly bool $isApi = false)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $user = $request->getAttribute('user');

        if ($user !== null && $user->isAdmin) {
            return $handler->handle($request);
        }

        $response = new ResponseFactory()->createResponse(403);

        if ($this->isApi) {
            return JsonResponse::error($response, 'FORBIDDEN', 'Kein Zugriff auf den Admin-Bereich.', 403);
        }

        $response->getBody()->write('Kein Zugriff auf den Admin-Bereich.');

        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}
