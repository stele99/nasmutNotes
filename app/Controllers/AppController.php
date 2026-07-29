<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Import\ZipImportService;
use App\Domain\Notes\NoteRewriteService;
use App\Domain\Notes\PageAttachmentService;
use App\Domain\PageService;
use App\Domain\Voice\VoiceNoteService;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use App\Support\Renderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AppController
{
    public function __construct(
        private readonly PageService $pages,
        private readonly Renderer $renderer,
        private readonly PageAttachmentService $attachments,
        private readonly ZipImportService $import,
        private readonly VoiceNoteService $voice,
        private readonly NoteRewriteService $rewriter,
    ) {
    }

    public function shell(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);
        $html = $this->renderer->page(
            $request,
            'app',
            ['isAdmin' => $user->isAdmin, ...$this->voiceViewData()],
            'Workspace',
        );
        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Liefert ein frisches CSRF-Token. Nötig, weil offline gecachtes HTML ein
     * abgelaufenes Token im <meta>-Tag transportieren kann und der spätere Sync
     * sonst dauerhaft an CSRF_FAILED scheitert. Zusätzlich trägt die Antwort die
     * Offline-Einstellungen, die der Client für den Prefetch braucht.
     */
    public function session(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);

        return JsonResponse::json($response, [
            'csrf_token' => (string) $request->getAttribute('csrf_token'),
            'user' => [
                'id' => $user->id,
                'is_admin' => $user->isAdmin,
                'nearby_search_radius_km' => $user->nearbySearchRadiusKm,
            ],
            'storage' => $this->pages->workspaceStats($user),
            'offline' => [
                'attachment_max_bytes' => $this->attachments->offlineAttachmentMaxBytes(),
            ],
            // Der Dialog überträgt in Teilen dieser Größe und kann ein zu großes
            // Archiv melden, bevor es vergeblich hochgeladen wird.
            'import' => [
                'max_archive_bytes' => $this->import->maxArchiveBytes(),
                'max_request_bytes' => $this->import->maxUploadBytes(),
                'chunk_size' => $this->import->chunkSize(),
            ],
            'voice' => [
                'enabled' => $this->voice->isUsable(),
                'max_seconds' => $this->voice->settings()->maxSeconds,
            ],
        ])->withHeader('Cache-Control', 'private, no-store');
    }

    /** @param array<string, string> $args */
    public function page(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $page = $this->pages->find($user, (int) $args['id']);

        $html = $this->renderer->page(
            $request,
            'page',
            ['isAdmin' => $user->isAdmin, 'page' => $page, ...$this->voiceViewData()],
            $page['title'],
        );
        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Die Aufnahmeknöpfe werden serverseitig ein- oder ausgeblendet, damit sie
     * ohne freigeschaltete Funktion gar nicht erst im Markup stehen.
     *
     * @return array{voiceEnabled: bool, voiceMaxSeconds: int, aiEnabled: bool}
     */
    private function voiceViewData(): array
    {
        $settings = $this->voice->settings();

        return [
            'voiceEnabled' => $settings->isUsable(),
            'voiceMaxSeconds' => $settings->maxSeconds,
            'aiEnabled' => $this->rewriter->isUsable(),
        ];
    }
}
