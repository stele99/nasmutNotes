<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Notes\NoteContentException;
use App\Domain\Notes\NoteEncryptionException;
use App\Domain\Notes\NoteService;
use App\Domain\Notes\NoteWriteUnavailableException;
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

        return $this->noStore(JsonResponse::json($response, $result));
    }

    /** @param array<string, string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        if (!$this->rateLimiter->attempt("autosave:{$user->id}", 60, 60)) {
            $response = new ResponseFactory()->createResponse(429)->withHeader('Retry-After', '60');

            return JsonResponse::error($response, 'RATE_LIMITED', 'Zu viele Speichervorgänge. Bitte kurz warten.', 429);
        }

        $body = (array) ($request->getParsedBody() ?? []);

        if (!isset($body['content']) || !is_array($body['content'])) {
            throw new ValidationException('Feld "content" fehlt oder ist kein Objekt.');
        }
        if (!isset($body['version']) || !is_int($body['version'])) {
            throw new ValidationException('Feld "version" fehlt oder ist keine Ganzzahl.');
        }
        if (!isset($body['expected_encryption_state']) || !is_string($body['expected_encryption_state'])) {
            throw new ValidationException('Feld "expected_encryption_state" fehlt oder ist ungültig.');
        }

        $forceSnapshot = ($body['force_snapshot'] ?? false) === true;

        try {
            $result = $this->notes->save(
                $user,
                (int) $args['id'],
                $body['content'],
                (int) $body['version'],
                $forceSnapshot,
                $body['expected_encryption_state'],
            );
        } catch (VersionConflictException $e) {
            return $this->versionConflictResponse($response, $e);
        } catch (NoteEncryptionException $e) {
            return $this->encryptionError($response, $e);
        } catch (NoteContentException $e) {
            return JsonResponse::error($response, 'INVALID_NOTE_CONTENT', $e->getMessage(), 422);
        } catch (NoteWriteUnavailableException $e) {
            return JsonResponse::error(
                $response->withHeader('Retry-After', '1'),
                'NOTE_BUSY',
                $e->getMessage(),
                503,
            );
        }

        return $this->noStore(JsonResponse::json($response, $result));
    }

    /** @param array<string, string> $args */
    public function updateEncryption(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        if (!$this->rateLimiter->attempt("note-encryption:{$user->id}", 20, 300)) {
            return JsonResponse::error(
                $response->withHeader('Retry-After', '60'),
                'RATE_LIMITED',
                'Zu viele Verschlüsselungsänderungen. Bitte kurz warten.',
                429,
            );
        }

        $body = (array) ($request->getParsedBody() ?? []);
        if (!is_string($body['transition'] ?? null)) {
            throw new ValidationException('Feld "transition" fehlt oder ist ungültig.');
        }
        if (!is_array($body['content'] ?? null)) {
            throw new ValidationException('Feld "content" fehlt oder ist kein Objekt.');
        }
        if (!is_int($body['version'] ?? null)) {
            throw new ValidationException('Feld "version" fehlt oder ist keine Ganzzahl.');
        }
        if (!is_string($body['expected_encryption_state'] ?? null)) {
            throw new ValidationException('Feld "expected_encryption_state" fehlt oder ist ungültig.');
        }

        try {
            $result = $this->notes->transitionEncryption(
                $user,
                (int) $args['id'],
                $body['transition'],
                $body['content'],
                $body['version'],
                $body['expected_encryption_state'],
            );
        } catch (VersionConflictException $e) {
            return $this->versionConflictResponse($response, $e);
        } catch (NoteEncryptionException $e) {
            return $this->encryptionError($response, $e);
        } catch (NoteContentException $e) {
            return JsonResponse::error($response, 'INVALID_NOTE_CONTENT', $e->getMessage(), 422);
        } catch (NoteWriteUnavailableException $e) {
            return JsonResponse::error(
                $response->withHeader('Retry-After', '1'),
                'NOTE_BUSY',
                $e->getMessage(),
                503,
            );
        }

        return $this->noStore(JsonResponse::json($response, $result));
    }

    /** @param array<string, string> $args */
    public function versions(Request $request, Response $response, array $args): Response
    {
        try {
            $result = $this->notes->listVersions(CurrentUser::require($request), (int) $args['id']);
        } catch (NoteEncryptionException $e) {
            return $this->encryptionError($response, $e);
        }

        return JsonResponse::json($response, $result);
    }

    /** @param array<string, string> $args */
    public function showVersion(Request $request, Response $response, array $args): Response
    {
        try {
            $result = $this->notes->getVersion(
                CurrentUser::require($request),
                (int) $args['id'],
                (int) $args['vid'],
            );
        } catch (NoteEncryptionException $e) {
            return $this->encryptionError($response, $e);
        }

        return $this->noStore(JsonResponse::json($response, $result));
    }

    /** @param array<string, string> $args */
    public function restoreVersion(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        if (!$this->rateLimiter->attempt("restore:{$user->id}", 10, 60)) {
            $response = new ResponseFactory()->createResponse(429)->withHeader('Retry-After', '60');

            return JsonResponse::error($response, 'RATE_LIMITED', 'Zu viele Wiederherstellungen. Bitte kurz warten.', 429);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        if (!isset($body['version']) || !is_int($body['version'])) {
            throw new ValidationException('Feld "version" fehlt oder ist keine Ganzzahl.');
        }

        try {
            $result = $this->notes->restoreVersion(
                $user,
                (int) $args['id'],
                (int) $args['vid'],
                $body['version'],
            );
        } catch (VersionConflictException $e) {
            return $this->versionConflictResponse($response, $e);
        } catch (NoteEncryptionException $e) {
            return $this->encryptionError($response, $e);
        } catch (NoteWriteUnavailableException $e) {
            return JsonResponse::error(
                $response->withHeader('Retry-After', '1'),
                'NOTE_BUSY',
                $e->getMessage(),
                503,
            );
        }

        return $this->noStore(JsonResponse::json($response, $result));
    }

    private function encryptionError(Response $response, NoteEncryptionException $exception): Response
    {
        $body = [
            'error' => [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ],
        ];
        if ($exception->context !== []) {
            $body += $exception->context;
        }

        return $this->noStore(JsonResponse::json($response, $body, $exception->status));
    }

    private function versionConflictResponse(Response $response, VersionConflictException $exception): Response
    {
        return $this->noStore(JsonResponse::json($response, [
            'error' => [
                'code' => 'VERSION_CONFLICT',
                'message' => $exception->getMessage(),
            ],
            'current' => [
                'content' => $exception->currentContent,
                'version' => $exception->currentVersion,
                'updated_at' => $exception->currentUpdatedAt,
                'last_editor_name' => $exception->currentEditorName,
                'encryption_state' => $exception->encryptionState,
            ],
        ], 409));
    }

    private function noStore(Response $response): Response
    {
        return $response->withHeader('Cache-Control', 'no-store');
    }
}
