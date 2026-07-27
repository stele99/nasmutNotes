<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Notes\NoteService;
use App\Domain\Notes\VersionConflictException;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use App\Support\RateLimiter;
use App\Support\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\ResponseFactory;

final class NoteController
{
    public function __construct(
        private readonly NoteService $notes,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $result = $this->notes->get(CurrentUser::require($request), (int) $args['id']);

        return JsonResponse::json($response, $result);
    }

    /** @param array<string, string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        if (!$this->rateLimiter->attempt("autosave:{$user->id}", 60, 60)) {
            $response = new ResponseFactory()->createResponse(429);

            return JsonResponse::error($response, 'RATE_LIMITED', 'Zu viele Speichervorgänge. Bitte kurz warten.', 429);
        }

        $body = (array) ($request->getParsedBody() ?? []);

        if (!isset($body['content']) || !is_array($body['content'])) {
            throw new ValidationException('Feld "content" fehlt oder ist kein Objekt.');
        }
        if (!isset($body['version']) || !is_int($body['version'])) {
            throw new ValidationException('Feld "version" fehlt oder ist keine Ganzzahl.');
        }

        $forceSnapshot = ($body['force_snapshot'] ?? false) === true;

        try {
            $result = $this->notes->save(
                $user,
                (int) $args['id'],
                $body['content'],
                (int) $body['version'],
                $forceSnapshot,
            );
        } catch (VersionConflictException $e) {
            return JsonResponse::json($response, [
                'error' => [
                    'code' => 'VERSION_CONFLICT',
                    'message' => $e->getMessage(),
                ],
                'current' => [
                    'content' => $e->currentContent,
                    'version' => $e->currentVersion,
                    'updated_at' => $e->currentUpdatedAt,
                    'last_editor_name' => $e->currentEditorName,
                ],
            ], 409);
        }

        return JsonResponse::json($response, $result);
    }

    /** @param array<string, string> $args */
    public function versions(Request $request, Response $response, array $args): Response
    {
        $result = $this->notes->listVersions(CurrentUser::require($request), (int) $args['id']);

        return JsonResponse::json($response, $result);
    }

    /** @param array<string, string> $args */
    public function showVersion(Request $request, Response $response, array $args): Response
    {
        $result = $this->notes->getVersion(
            CurrentUser::require($request),
            (int) $args['id'],
            (int) $args['vid'],
        );

        return JsonResponse::json($response, $result);
    }

    /** @param array<string, string> $args */
    public function restoreVersion(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);

        try {
            $result = $this->notes->restoreVersion(
                $user,
                (int) $args['id'],
                (int) $args['vid'],
            );
        } catch (VersionConflictException $e) {
            return JsonResponse::json($response, [
                'error' => [
                    'code' => 'VERSION_CONFLICT',
                    'message' => $e->getMessage(),
                ],
                'current' => [
                    'content' => $e->currentContent,
                    'version' => $e->currentVersion,
                    'updated_at' => $e->currentUpdatedAt,
                    'last_editor_name' => $e->currentEditorName,
                ],
            ], 409);
        }

        return JsonResponse::json($response, $result);
    }
}
