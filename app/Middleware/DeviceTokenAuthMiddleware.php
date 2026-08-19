<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Domain\Auth\DeviceTokenService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Löst einen `Authorization: Bearer`-Automations-Token zum Nutzer auf
 * (NotesVoice, FR-NVOICE). Anders als SessionAuthMiddleware bewusst nicht
 * global, sondern nur an der einen Route registriert, die Token akzeptiert.
 *
 * Ist `user` bereits gesetzt (normale, eingeloggte Browser-Session), bleibt
 * das unangetastet - Kurzbefehle schickt ohnehin nie das Session-Cookie.
 */
final class DeviceTokenAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly DeviceTokenService $tokens)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        if ($request->getAttribute('user') === null) {
            $header = $request->getHeaderLine('Authorization');
            if (str_starts_with($header, 'Bearer ')) {
                $rawToken = trim(substr($header, 7));
                $user = $rawToken !== '' ? $this->tokens->resolveUser($rawToken) : null;
                if ($user !== null) {
                    $request = $request->withAttribute('user', $user);
                }
            }
        }

        return $handler->handle($request);
    }
}
