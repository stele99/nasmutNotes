<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

final class RequireAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly bool $isApi = false)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        if ($request->getAttribute('user') !== null) {
            return $handler->handle($request);
        }

        if ($this->isApi) {
            return JsonResponse::error(new ResponseFactory()->createResponse(401), 'UNAUTHORIZED', 'Anmeldung erforderlich.', 401);
        }

        $response = new ResponseFactory()->createResponse(302);

        return $response->withHeader('Location', '/');
    }
}
