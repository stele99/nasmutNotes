<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Domain\SessionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

final class SessionAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly SessionService $sessions)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $cookies = $request->getCookieParams();
        $token = $cookies[SessionService::COOKIE_NAME] ?? null;

        $user = null;
        if (is_string($token) && $token !== '') {
            $user = $this->sessions->resolveUser($token);
        }

        return $handler->handle($request->withAttribute('user', $user));
    }
}
