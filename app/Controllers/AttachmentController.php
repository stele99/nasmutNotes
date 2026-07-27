<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Notes\AttachmentService;
use App\Domain\Notes\ImageCompressionService;
use App\Repositories\AuditLogRepository;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use App\Support\RateLimiter;
use App\Support\RequestIp;
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
        private readonly ImageCompressionService $compression,
        private readonly AuditLogRepository $auditLog,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    /** @param array<string, string> $args */
    public function compress(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        if (!$this->rateLimiter->attempt("attachment-compress:{$user->id}", 5, 300)) {
            $response = new ResponseFactory()->createResponse(429);

            return JsonResponse::error($response, 'RATE_LIMITED', 'Zu viele Kompressionsläufe. Bitte kurz warten.', 429);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $result = $this->compression->compress(
            $user,
            (int) $args['id'],
            (int) ($body['quality'] ?? 82),
            (string) ($body['size'] ?? 'screen'),
        );

        return JsonResponse::json($response, $result);
    }

    public function compressAll(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);
        if (!$this->rateLimiter->attempt("user-image-compress:{$user->id}", 3, 300)) {
            return JsonResponse::error(
                $response,
                'RATE_LIMITED',
                'Zu viele Kompressionsläufe. Bitte kurz warten.',
                429,
            );
        }

        set_time_limit(600);
        $result = $this->compression->compressForUser($user->id, 82, 'screen');
        $this->auditLog->log($user->id, 'own_images_compressed', 'user', $user->id, RequestIp::hash($request), $result);

        return JsonResponse::json($response, $result);
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
            // Kein HTTP-Caching: der Offline-Modus legt Bilder bewusst in der
            // Cache-Storage-API ab, die Cache-Control ignoriert. So bleibt ein
            // entzogener Zugriff sofort wirksam.
            ->withHeader('Cache-Control', 'private, no-store');
    }
}
