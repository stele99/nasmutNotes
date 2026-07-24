<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Notes\AttachmentService;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use App\Support\RateLimiter;
use App\Support\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Stream;

final class AttachmentController
{
    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    /** @param array<string, string> $args */
    public function store(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        if (!$this->rateLimiter->attempt("attachment-upload:{$user->id}", 20, 60)) {
            $response = new ResponseFactory()->createResponse(429);

            return JsonResponse::error($response, 'RATE_LIMITED', 'Zu viele Bild-Uploads. Bitte kurz warten.', 429);
        }

        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            throw new ValidationException('Es wurde keine Bilddatei übermittelt.');
        }

        $attachment = $this->attachments->upload($user, (int) $args['id'], $file);

        return JsonResponse::json($response, $attachment, 201);
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $attachment = $this->attachments->open(CurrentUser::require($request), (string) $args['token']);
        $resource = fopen($attachment['path'], 'rb');
        if ($resource === false) {
            throw new \RuntimeException('Das Bild konnte nicht geöffnet werden.');
        }

        return $response
            ->withBody(new Stream($resource))
            ->withHeader('Content-Type', $attachment['mime_type'])
            ->withHeader('Content-Length', (string) $attachment['byte_size'])
            ->withHeader('Content-Disposition', 'inline')
            ->withHeader('Cache-Control', 'private, no-store');
    }
}
