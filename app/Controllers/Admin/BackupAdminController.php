<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Domain\Backup\BackupService;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use App\Support\RateLimiter;
use App\Support\Renderer;
use App\Support\RequestIp;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * Sicherungen im Admin-Bereich: anlegen, auflisten, herunterladen, löschen
 * (NFR-OPS-06).
 *
 * Bewusst ohne Restore-Endpunkt - das Zurückspielen läuft ausschließlich über
 * `php bin/console.php backup:restore`. Ein Knopf, der die Livedatenbank
 * ersetzt, wäre die gefährlichste Schaltfläche der Anwendung.
 */
final class BackupAdminController
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly Renderer $renderer,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    public function page(Request $request, Response $response): Response
    {
        $html = $this->renderer->page($request, 'admin/backups', [], 'Admin · Sicherungen');
        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function index(Request $request, Response $response): Response
    {
        return JsonResponse::json($response, [
            'snapshots' => $this->backups->snapshots(),
            'stats' => $this->backups->stats(),
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $admin = CurrentUser::require($request);
        if (!$this->rateLimiter->attempt("admin-backup-create:{$admin->id}", 5, 300)) {
            return JsonResponse::error(
                $response,
                'RATE_LIMITED',
                'Zu viele Sicherungsläufe. Bitte kurz warten.',
                429,
            );
        }

        // Der Datenbankabzug ist schnell, das erstmalige Kopieren aller Uploads
        // kann bei vielen Anhängen dauern.
        set_time_limit(600);
        $result = $this->backups->create($admin, RequestIp::hash($request));

        return JsonResponse::json($response, $result);
    }

    /** @param array<string, string> $args */
    public function download(Request $request, Response $response, array $args): Response
    {
        $admin = CurrentUser::require($request);
        if (!$this->rateLimiter->attempt("admin-backup-download:{$admin->id}", 10, 300)) {
            return JsonResponse::error(
                $response,
                'RATE_LIMITED',
                'Zu viele Downloads. Bitte kurz warten.',
                429,
            );
        }

        set_time_limit(600);
        $archive = $this->backups->archive(
            (string) ($args['id'] ?? ''),
            $admin,
            RequestIp::hash($request),
        );

        $handle = fopen($archive['path'], 'rb');
        if ($handle === false) {
            return JsonResponse::error($response, 'NOT_FOUND', 'Das Archiv ist nicht lesbar.', 404);
        }

        return $response
            ->withBody(new Stream($handle))
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Length', (string) $archive['bytes'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $archive['filename'] . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /** @param array<string, string> $args */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $admin = CurrentUser::require($request);
        $this->backups->delete((string) ($args['id'] ?? ''), $admin, RequestIp::hash($request));

        return JsonResponse::json($response, ['deleted' => true]);
    }
}
