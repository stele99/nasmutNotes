<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Notes\AttachmentService;
use App\Domain\Notes\PageAttachmentService;
use App\Domain\PageService;
use App\Repositories\AuditLogRepository;
use App\Support\CurrentUser;
use App\Support\Env;
use App\Support\JsonResponse;
use App\Support\RequestIp;
use App\Support\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PageController
{
    public function __construct(
        private readonly PageService $pages,
        private readonly AuditLogRepository $auditLog,
        private readonly AttachmentService $attachments,
        private readonly PageAttachmentService $files,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $sort = is_string($params['sort'] ?? null) ? $params['sort'] : 'updated';
        $type = isset($params['type']) && in_array($params['type'], ['note', 'task'], true) ? $params['type'] : null;
        $trashed = ($params['trashed'] ?? '0') === '1';
        $notebookId = isset($params['notebook_id']) && ctype_digit((string) $params['notebook_id'])
            ? (int) $params['notebook_id']
            : null;
        $collection = is_string($params['collection'] ?? null)
            ? $params['collection']
            : (is_string($params['scope'] ?? null) ? $params['scope'] : null);

        $pages = $this->pages->list(CurrentUser::require($request), $sort, $type, $trashed, $notebookId, $collection);

        return JsonResponse::json($response, [
            'pages' => array_map([$this, 'serialize'], $pages),
            // Der Papierkorb zeigt die verbleibende Frist je Seite - dafür muss
            // der Client die konfigurierte Aufbewahrungsdauer kennen.
            'trash_retention_days' => Env::int('TRASH_RETENTION_DAYS', 90),
        ]);
    }

    /**
     * Leert den Papierkorb vollständig: Alle Seiten samt Bilddateien werden
     * endgültig entfernt (FR-WS-06).
     */
    public function emptyTrash(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);
        $purged = 0;

        foreach ($this->pages->list($user, 'updated', null, true) as $page) {
            $pageId = (int) $page['id'];
            $storageNames = $this->attachments->storageNamesForOwnedPage($user, $pageId);
            $fileNames = $this->files->storageNamesForPage($user, $pageId);
            $this->pages->purge($user, $pageId);
            $this->attachments->deleteStoredFiles($storageNames);
            $this->files->deleteStoredFiles($fileNames);
            ++$purged;
        }

        if ($purged > 0) {
            $this->auditLog->log($user->id, 'trash_emptied', null, null, RequestIp::hash($request), [
                'pages' => $purged,
            ]);
        }

        return JsonResponse::json($response, ['purged' => $purged]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $notebookId = null;
        if (array_key_exists('notebook_id', $body) && $body['notebook_id'] !== null) {
            if (!is_int($body['notebook_id']) && !(is_string($body['notebook_id']) && ctype_digit($body['notebook_id']))) {
                throw new ValidationException('Ungültiges Notizbuch.');
            }
            $notebookId = (int) $body['notebook_id'];
        }
        // Die optionale clientseitige Kennung gehört zu einer offline
        // angelegten Seite; ein Wiederholungsversuch erhält die bereits
        // angelegte Seite als Antwort (200) statt eines Duplikats (201).
        $clientUuid = null;
        if (array_key_exists('client_uuid', $body) && $body['client_uuid'] !== null) {
            if (!is_string($body['client_uuid'])) {
                throw new ValidationException('Ungültige clientseitige Kennung.');
            }
            $clientUuid = $body['client_uuid'];
        }
        $result = $this->pages->createOrReplay(
            CurrentUser::require($request),
            (string) ($body['type'] ?? ''),
            (string) ($body['title'] ?? ''),
            isset($body['icon']) ? (string) $body['icon'] : null,
            $notebookId,
            is_array($body['location'] ?? null) ? $body['location'] : null,
            $clientUuid,
        );

        return JsonResponse::json($response, $this->serialize($result['page']), $result['created'] ? 201 : 200);
    }

    public function moveMany(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $rawIds = is_array($body['page_ids'] ?? null) ? $body['page_ids'] : [];
        $pageIds = array_values(array_map(static fn (mixed $id): int => (int) $id, $rawIds));
        $notebookId = $body['notebook_id'] ?? null;
        if ($notebookId !== null && (!is_int($notebookId) && !(is_string($notebookId) && ctype_digit($notebookId)))) {
            throw new \App\Support\ValidationException('Ungültiges Notizbuch.');
        }

        $moved = $this->pages->moveMany(
            CurrentUser::require($request),
            $pageIds,
            $notebookId !== null ? (int) $notebookId : null,
        );

        return JsonResponse::json($response, ['moved' => $moved]);
    }

    public function trashMany(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $rawIds = is_array($body['page_ids'] ?? null) ? $body['page_ids'] : [];
        $pageIds = array_values(array_map(static fn (mixed $id): int => (int) $id, $rawIds));
        $user = CurrentUser::require($request);
        $trashed = $this->pages->softDeleteMany($user, $pageIds);

        if ($trashed > 0) {
            $this->auditLog->log($user->id, 'pages_deleted', null, null, RequestIp::hash($request), [
                'pages' => $trashed,
            ]);
        }

        return JsonResponse::json($response, ['trashed' => $trashed]);
    }

    /** @param array<string, string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $page = $this->pages->update(CurrentUser::require($request), (int) $args['id'], $body);

        return JsonResponse::json($response, $this->serialize($page));
    }

    /** @param array<string, string> $args */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $pageId = (int) $args['id'];
        $this->pages->softDelete($user, $pageId);
        $this->auditLog->log($user->id, 'page_deleted', 'page', $pageId, RequestIp::hash($request));

        return $response->withStatus(204);
    }

    /** @param array<string, string> $args */
    public function restore(Request $request, Response $response, array $args): Response
    {
        $this->pages->restore(CurrentUser::require($request), (int) $args['id']);

        return $response->withStatus(204);
    }

    /** @param array<string, string> $args */
    public function purge(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $pageId = (int) $args['id'];
        $storageNames = $this->attachments->storageNamesForOwnedPage($user, $pageId);
        $fileNames = $this->files->storageNamesForPage($user, $pageId);
        $this->pages->purge($user, $pageId);
        $this->attachments->deleteStoredFiles($storageNames);
        $this->files->deleteStoredFiles($fileNames);
        $this->auditLog->log($user->id, 'page_purged', 'page', $pageId, RequestIp::hash($request));

        return $response->withStatus(204);
    }

    /**
     * Aufnahmeort einer Seite; fehlt er, liefert die Antwort null statt eines
     * halb gefüllten Objekts (FR-NOTE-25).
     *
     * @param array<string, mixed> $page
     * @return array{lat: float, lon: float, accuracy: ?float, label: ?string, captured_at: ?string}|null
     */
    public static function serializeLocation(array $page): ?array
    {
        if (($page['location_lat'] ?? null) === null || ($page['location_lon'] ?? null) === null) {
            return null;
        }

        return [
            'lat' => (float) $page['location_lat'],
            'lon' => (float) $page['location_lon'],
            'accuracy' => ($page['location_accuracy'] ?? null) !== null ? (float) $page['location_accuracy'] : null,
            'label' => ($page['location_label'] ?? null) !== null ? (string) $page['location_label'] : null,
            'captured_at' => ($page['location_at'] ?? null) !== null ? (string) $page['location_at'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function serialize(array $page): array
    {
        return [
            'id' => (int) $page['id'],
            'type' => $page['type'],
            'title' => $page['title'],
            'icon' => $page['icon'],
            'is_favorite' => ((int) $page['is_favorite']) === 1,
            'sort_order' => (int) $page['sort_order'],
            'default_view' => $page['default_view'],
            'notebook_id' => ($page['is_shared'] ?? false) === true ? null : ($page['notebook_id'] !== null ? (int) $page['notebook_id'] : null),
            'notebook_name' => ($page['is_shared'] ?? false) === true ? null : ($page['notebook_name'] ?? null),
            'notebook_icon' => ($page['is_shared'] ?? false) === true ? null : ($page['notebook_icon'] ?? null),
            'notebook_color' => ($page['is_shared'] ?? false) === true ? null : ($page['notebook_color'] ?? null),
            'location' => self::serializeLocation($page),
            'deleted_at' => $page['deleted_at'],
            'created_at' => $page['created_at'],
            'updated_at' => $page['updated_at'],
            'is_encrypted' => (bool) ($page['is_encrypted'] ?? false),
            // Beim Diktat verwendete Vorlage: Ein weiteres Diktat in diese
            // Notiz greift sie wieder auf, ohne erneut zu fragen (FR-VOICE-12).
            'voice_template_id' => isset($page['voice_template_id']) ? (int) $page['voice_template_id'] : null,
            'is_shared' => ($page['is_shared'] ?? false) === true,
            'share_permission' => $page['share_permission'] ?? null,
            // „page" = Seitenfreigabe (verlassbar), „notebook" = Teilnahme
            // über ein geteiltes Notizbuch (nur am Notizbuch verlassbar).
            'share_source' => isset($page['share_source']) ? (string) $page['share_source'] : null,
            'can_edit' => ($page['can_edit'] ?? true) === true,
            // Nur die Listenabfrage reichert diese Felder an; einzeln
            // ausgelieferte Seiten liefern hier null bzw. keine Zahlen.
            'preview' => $page['preview'] ?? null,
            'last_editor_name' => $page['last_editor_name'] ?? null,
            'task_count' => isset($page['task_count']) ? (int) $page['task_count'] : null,
            'open_task_count' => isset($page['open_task_count']) ? (int) $page['open_task_count'] : null,
            'attachment_count' => isset($page['attachment_count']) ? (int) $page['attachment_count'] : 0,
            'log_entry_count' => isset($page['log_entry_count']) ? (int) $page['log_entry_count'] : null,
            'latest_entry_at' => $page['latest_entry_at'] ?? null,
        ];
    }
}
