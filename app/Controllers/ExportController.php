<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Export\NotebookExportService;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use App\Support\RateLimiter;
use App\Support\RequestIp;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * Export ausgewählter Notizbücher als ZIP (FR-EXP-03).
 */
final class ExportController
{
    public function __construct(
        private readonly NotebookExportService $export,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    public function notebooks(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);

        return JsonResponse::json($response, ['notebooks' => $this->export->selectable($user)]);
    }

    /**
     * Bewusst ein GET: Der Browser lädt das Archiv dann selbst herunter und
     * streamt es auf die Platte, statt es im Speicher zu halten.
     */
    public function workspace(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);
        if (!$this->rateLimiter->attempt("export:{$user->id}", 5, 300)) {
            return JsonResponse::error(
                $response,
                'RATE_LIMITED',
                'Zu viele Exporte. Bitte kurz warten.',
                429,
            );
        }

        $params = $request->getQueryParams();
        $raw = is_string($params['notebooks'] ?? null) ? $params['notebooks'] : '';
        $notebookIds = array_values(array_filter(
            array_map('trim', $raw === '' ? [] : explode(',', $raw)),
            static fn (string $value): bool => ctype_digit($value),
        ));
        $includeUnassigned = ($params['unassigned'] ?? '0') === '1';

        set_time_limit(600);
        $archive = $this->export->export(
            $user,
            array_map('intval', $notebookIds),
            $includeUnassigned,
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
}
