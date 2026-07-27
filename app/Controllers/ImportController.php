<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Import\ArchiveChunkStore;
use App\Domain\Import\ZipImportService;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use App\Support\RequestIp;
use App\Support\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Import eines Markdown-Archivs aus einem anderen Notizwerkzeug (FR-IMP-19).
 *
 * Zwei Wege: das Archiv in einer Anfrage (einfach, aber an `post_max_size`
 * gebunden) oder in Teilen (FR-IMP-25) — die Oberfläche nutzt immer die Teile,
 * damit die Serverkonfiguration keine Rolle spielt.
 */
final class ImportController
{
    public function __construct(
        private readonly ZipImportService $import,
        private readonly ArchiveChunkStore $chunks,
    ) {
    }

    public function store(Request $request, Response $response): Response
    {
        $file = $request->getUploadedFiles()['file'] ?? null;

        if (!$file instanceof UploadedFileInterface) {
            return $this->missingUploadError($response);
        }

        $this->extendRuntime();
        $report = $this->import->import(
            CurrentUser::require($request),
            $file,
            RequestIp::hash($request),
        );

        return JsonResponse::json($response, $report->toArray());
    }

    /** Beginnt einen geteilten Upload und nennt die Größe der Teile. */
    public function begin(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);
        $body = (array) ($request->getParsedBody() ?? []);

        $id = $this->chunks->begin(
            $user->id,
            (string) ($body['file_name'] ?? 'import.zip'),
            (int) ($body['size'] ?? 0),
            $this->import->maxArchiveBytes(),
        );

        return JsonResponse::json($response, [
            'upload_id' => $id,
            'chunk_size' => $this->import->chunkSize(),
        ], 201);
    }

    /** @param array<string, string> $args */
    public function append(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $chunk = $request->getUploadedFiles()['chunk'] ?? null;

        if (!$chunk instanceof UploadedFileInterface) {
            return $this->missingUploadError($response);
        }
        if ($chunk->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException('Ein Teil des Archivs konnte nicht übertragen werden.');
        }

        $stream = $chunk->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $state = $this->chunks->append(
            $user->id,
            (string) ($args['id'] ?? ''),
            (int) ($body['index'] ?? -1),
            $stream->getContents(),
        );

        return JsonResponse::json($response, $state);
    }

    /**
     * Schließt den geteilten Upload ab und importiert das zusammengesetzte
     * Archiv. Die Teile werden anschließend in jedem Fall entfernt.
     *
     * @param array<string, string> $args
     */
    public function complete(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $id = (string) ($args['id'] ?? '');
        $archive = $this->chunks->finish($user->id, $id);

        $this->extendRuntime();

        try {
            $report = $this->import->importFromPath($user, $archive['path'], RequestIp::hash($request));
        } finally {
            $this->chunks->discard($id);
        }

        return JsonResponse::json($response, $report->toArray());
    }

    /** @param array<string, string> $args */
    public function abort(Request $request, Response $response, array $args): Response
    {
        $this->chunks->abandon(CurrentUser::require($request)->id, (string) ($args['id'] ?? ''));

        return $response->withStatus(204);
    }

    private function missingUploadError(Response $response): Response
    {
        $limits = $this->import->phpUploadLimits();

        return JsonResponse::error(
            $response,
            'UPLOAD_TOO_LARGE',
            'Es wurde keine Datei übertragen. PHP nimmt derzeit höchstens '
            . "{$limits['upload_max_filesize']} je Datei und {$limits['post_max_size']} je Anfrage an "
            . '(upload_max_filesize / post_max_size in der php.ini); größere Anfragen weist der Server ab, '
            . 'bevor die Anwendung sie sieht.',
            422,
        );
    }

    /**
     * Der Import legt hunderte Seiten samt Anhängen an; die Standardlaufzeit
     * eines Requests reicht dafür nicht zuverlässig.
     */
    private function extendRuntime(): void
    {
        set_time_limit(600);
    }
}
