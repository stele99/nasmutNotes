<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Repositories\AuditLogRepository;
use App\Support\JsonResponse;
use App\Support\Renderer;
use App\Support\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Audit-Log im Admin-Bereich: wer hat wann geteilt, gelöscht, deaktiviert.
 * Gezeigt werden nur die protokollierten Eckdaten - Inhalte stehen nie im Log.
 */
final class AuditAdminController
{
    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly AuditLogRepository $auditLog,
        private readonly Renderer $renderer,
    ) {
    }

    public function page(Request $request, Response $response): Response
    {
        $html = $this->renderer->page($request, 'admin/audit', [], 'Admin · Protokoll');
        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $userId = isset($params['user_id']) && ctype_digit((string) $params['user_id']) ? (int) $params['user_id'] : null;
        $action = is_string($params['action'] ?? null) && $params['action'] !== '' ? $params['action'] : null;
        $offset = isset($params['offset']) && ctype_digit((string) $params['offset']) ? (int) $params['offset'] : 0;

        $rows = $this->auditLog->search(
            $userId,
            $action,
            $this->day($params['from'] ?? null, false),
            $this->day($params['to'] ?? null, true),
            self::PAGE_SIZE,
            $offset,
        );

        return JsonResponse::json($response, [
            'entries' => array_map([self::class, 'serialize'], array_slice($rows, 0, self::PAGE_SIZE)),
            'has_more' => count($rows) > self::PAGE_SIZE,
            'actions' => $this->auditLog->actions(),
        ]);
    }

    /**
     * `YYYY-MM-DD` als UTC-Tagesgrenze; beim Bis-Datum zählt der ganze Tag.
     */
    private function day(mixed $value, bool $endOfDay): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new ValidationException('Ungültiges Datum.');
        }

        return ($endOfDay ? $date->modify('+1 day') : $date)->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function serialize(array $row): array
    {
        $metadata = json_decode((string) $row['metadata'], true);

        return [
            'id' => (int) $row['id'],
            'created_at' => (string) $row['created_at'],
            'action' => (string) $row['action'],
            'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'user' => $row['user_email'] !== null
                ? (trim((string) $row['user_name']) !== '' ? (string) $row['user_name'] : (string) $row['user_email'])
                : null,
            'object_type' => $row['object_type'],
            'object_id' => $row['object_id'] !== null ? (int) $row['object_id'] : null,
            'metadata' => is_array($metadata) ? $metadata : [],
        ];
    }
}
