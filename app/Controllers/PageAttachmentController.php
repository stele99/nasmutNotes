<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Notes\PageAttachmentService;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

final class PageAttachmentController
{
    public function __construct(private readonly PageAttachmentService $attachments)
    {
    }

    /** @param array<string, string> $args */
    public function index(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);

        return JsonResponse::json($response, [
            'attachments' => $this->attachments->listForPage($user, (int) $args['id']),
            'max_attachment_mb' => $this->attachments->maxAttachmentMb(),
            'offline_attachment_max_bytes' => $this->attachments->offlineAttachmentMaxBytes(),
        ]);
    }

    /** @param array<string, string> $args */
    public function store(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;

        if (!$file instanceof \Psr\Http\Message\UploadedFileInterface) {
            return JsonResponse::error($response, 'VALIDATION_FAILED', 'Es wurde keine Datei übertragen.', 422);
        }

        $attachment = $this->attachments->upload($user, (int) $args['id'], $file);

        return JsonResponse::json($response, $attachment, 201);
    }

    /**
     * Liefert die Datei aus. PDFs gehen `inline` heraus, damit der Betrachter
     * sie direkt anzeigen kann; alles andere wird als Download angeboten
     * (FR-NOTE-20).
     *
     * Da jeder Dateityp anhängbar ist (FR-NOTE-21), trägt diese Stelle den
     * Schutz: Nur für PDF wird der echte Content-Type gesendet, sonst ein
     * neutraler. Ein HTML- oder SVG-Anhang kann damit nicht im Ursprung der
     * Anwendung gerendert werden, selbst wenn ein Browser die Disposition
     * ignoriert.
     *
     * @param array<string, string> $args
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $file = $this->attachments->open($user, (int) $args['id']);

        $handle = fopen($file['path'], 'rb');
        if ($handle === false) {
            return JsonResponse::error($response, 'NOT_FOUND', 'Die Datei ist nicht lesbar.', 404);
        }

        $isPdf = $file['mime_type'] === 'application/pdf';
        $disposition = $isPdf ? 'inline' : 'attachment';
        $fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', $file['original_name']) ?? 'anhang';

        return $response
            ->withBody(new Stream($handle))
            ->withHeader('Content-Type', $isPdf ? $file['mime_type'] : 'application/octet-stream')
            ->withHeader('Content-Length', (string) $file['byte_size'])
            ->withHeader(
                'Content-Disposition',
                $disposition . '; filename="' . $fallbackName . '"'
                . "; filename*=UTF-8''" . rawurlencode($file['original_name']),
            )
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, max-age=300');
    }

    /** @param array<string, string> $args */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->attachments->delete(CurrentUser::require($request), (int) $args['id']);

        return $response->withStatus(204);
    }
}
