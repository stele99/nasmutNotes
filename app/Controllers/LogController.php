<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Log\LogColumnType;
use App\Domain\Log\LogExportService;
use App\Domain\Log\LogService;
use App\Domain\Voice\VoiceNoteService;
use App\Domain\Voice\VoiceServiceException;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use App\Support\RateLimiter;
use App\Support\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/**
 * Logbuch-Seiten: Spalten verwalten, Einträge anlegen und ändern - getippt oder
 * diktiert (FR-LOG-01..09).
 */
final class LogController
{
    public function __construct(
        private readonly LogService $log,
        private readonly LogExportService $export,
        private readonly VoiceNoteService $voice,
        private readonly RateLimiter $rateLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Bewusst ein GET: Der Browser lädt die Datei dann selbst herunter.
     *
     * @param array<string, string> $args
     */
    public function export(Request $request, Response $response, array $args): Response
    {
        $params = $request->getQueryParams();
        $format = is_string($params['format'] ?? null) ? $params['format'] : '';
        $timezone = is_string($params['tz'] ?? null) ? $params['tz'] : null;

        $file = $this->export->export(
            CurrentUser::require($request),
            (int) $args['id'],
            $format,
            $timezone,
        );

        $response->getBody()->write($file['body']);

        return $response
            ->withHeader('Content-Type', $file['mime'])
            ->withHeader('Content-Length', (string) strlen($file['body']))
            ->withHeader('Content-Disposition', 'attachment; filename="' . $file['filename'] . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $params = $request->getQueryParams();
        $board = $this->log->board(
            CurrentUser::require($request),
            (int) $args['id'],
            is_string($params['sort'] ?? null) ? $params['sort'] : null,
            is_string($params['direction'] ?? null) ? $params['direction'] : null,
        );

        return JsonResponse::json($response, [
            'columns' => array_map([self::class, 'serializeColumn'], $board['columns']),
            'entries' => array_map([self::class, 'serializeEntry'], $board['entries']),
            'entry_count' => $board['entry_count'],
            'sort' => $board['sort'],
            'direction' => $board['direction'],
            'types' => self::columnTypes(),
        ]);
    }

    /** @param array<string, string> $args */
    public function storeColumn(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $column = $this->log->createColumn(
            CurrentUser::require($request),
            (int) $args['id'],
            $body['name'] ?? '',
            $body['type'] ?? '',
        );

        return JsonResponse::json($response, self::serializeColumn($column), 201);
    }

    /** @param array<string, string> $args */
    public function updateColumn(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $user = CurrentUser::require($request);
        $columnId = (int) $args['id'];

        if (isset($body['move']) && in_array($body['move'], ['up', 'down'], true)) {
            $columns = $this->log->moveColumn($user, $columnId, (string) $body['move']);

            return JsonResponse::json($response, [
                'columns' => array_map([self::class, 'serializeColumn'], $columns),
            ]);
        }

        return JsonResponse::json($response, self::serializeColumn($this->log->updateColumn($user, $columnId, $body)));
    }

    /** @param array<string, string> $args */
    public function destroyColumn(Request $request, Response $response, array $args): Response
    {
        $this->log->deleteColumn(CurrentUser::require($request), (int) $args['id']);

        return $response->withStatus(204);
    }

    /** @param array<string, string> $args */
    public function storeEntry(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $entry = $this->log->createEntry(
            CurrentUser::require($request),
            (int) $args['id'],
            $body['occurred_at'] ?? null,
            is_array($body['values'] ?? null) ? $body['values'] : [],
        );

        return JsonResponse::json($response, self::serializeEntry($entry), 201);
    }

    /** @param array<string, string> $args */
    public function updateEntry(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $entry = $this->log->updateEntry(CurrentUser::require($request), (int) $args['id'], $body);

        return JsonResponse::json($response, self::serializeEntry($entry));
    }

    /** @param array<string, string> $args */
    public function destroyEntry(Request $request, Response $response, array $args): Response
    {
        $this->log->deleteEntry(CurrentUser::require($request), (int) $args['id']);

        return $response->withStatus(204);
    }

    /**
     * Diktierter Eintrag: Die Aufnahme wird transkribiert und auf die Spalten
     * des Logbuchs verteilt (FR-LOG-08).
     *
     * @param array<string, string> $args
     */
    public function storeVoiceEntry(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $pageId = (int) $args['id'];

        if (!$this->rateLimiter->attempt("voice-transcribe:{$user->id}", 20, 300)) {
            return JsonResponse::error(
                $response->withHeader('Retry-After', '60'),
                'RATE_LIMITED',
                'Zu viele Aufnahmen in kurzer Zeit. Bitte kurz warten.',
                429,
            );
        }

        $file = $request->getUploadedFiles()['audio'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            throw new ValidationException('Es wurde keine Aufnahme übermittelt.');
        }

        $columns = $this->log->columns($user, $pageId);
        if ($columns === []) {
            throw new ValidationException('Dieses Logbuch hat noch keine Spalten.');
        }

        $body = (array) ($request->getParsedBody() ?? []);
        set_time_limit(300);

        try {
            $result = $this->voice->transcribeForLog(
                $user,
                $file,
                $columns,
                is_string($body['now'] ?? null) ? $body['now'] : null,
            );
        } catch (VoiceServiceException $e) {
            $this->logger->warning('Sprachdienst fehlgeschlagen', ['message' => $e->getMessage()]);

            return JsonResponse::error($response, 'VOICE_SERVICE_FAILED', $e->getMessage(), 502);
        }

        // Ortsspalten kommen vom Gerät, nicht aus dem Transkript (FR-LOG-11).
        $values = array_replace($result['values'], $this->deviceLocationValues($columns, $body));
        $entry = $this->log->createEntry($user, $pageId, $result['occurred_at'], $values);

        return JsonResponse::json($response, [
            'entry' => self::serializeEntry($entry),
            'transcript' => $result['transcript'],
        ], 201);
    }

    /**
     * Jede Ortsspalte bekommt den Standort, den der Client zur Aufnahme
     * mitgeschickt hat (FR-LOG-11). Ohne Ortung bleiben sie leer - geraten
     * wird nichts. Die Anschrift dazu ermittelt der LogService.
     *
     * @param array<int, array<string, mixed>> $columns
     * @param array<string, mixed> $body
     * @return array<int, array{lat: string, lon: string, label: string}>
     */
    private function deviceLocationValues(array $columns, array $body): array
    {
        if (!isset($body['lat'], $body['lon']) || !is_scalar($body['lat']) || !is_scalar($body['lon'])) {
            return [];
        }

        $location = ['lat' => (string) $body['lat'], 'lon' => (string) $body['lon'], 'label' => ''];
        $values = [];
        foreach ($columns as $column) {
            if ((string) $column['type'] === LogColumnType::Location->value) {
                $values[(int) $column['id']] = $location;
            }
        }

        return $values;
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function columnTypes(): array
    {
        return array_map(
            static fn (LogColumnType $type): array => ['value' => $type->value, 'label' => $type->label()],
            LogColumnType::cases(),
        );
    }

    /**
     * @param array<string, mixed> $column
     * @return array<string, mixed>
     */
    public static function serializeColumn(array $column): array
    {
        return [
            'id' => (int) $column['id'],
            'name' => (string) $column['name'],
            'type' => (string) $column['type'],
            'position' => (int) $column['position'],
            'is_numeric' => LogColumnType::from((string) $column['type'])->isNumeric(),
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    public static function serializeEntry(array $entry): array
    {
        $values = [];
        foreach ((array) ($entry['values'] ?? []) as $columnId => $value) {
            $values[(string) $columnId] = [
                'text' => $value['value_text'] !== null ? (string) $value['value_text'] : null,
                'number' => $value['value_number'] !== null ? (float) $value['value_number'] : null,
                'lat' => $value['value_lat'] !== null ? (float) $value['value_lat'] : null,
                'lon' => $value['value_lon'] !== null ? (float) $value['value_lon'] : null,
            ];
        }

        return [
            'id' => (int) $entry['id'],
            'occurred_at' => (string) $entry['occurred_at'],
            'created_at' => (string) $entry['created_at'],
            'updated_at' => (string) $entry['updated_at'],
            'created_by_name' => $entry['created_by_name'] !== null ? (string) $entry['created_by_name'] : null,
            'values' => $values,
        ];
    }
}
