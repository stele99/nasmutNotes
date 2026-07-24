<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\JsonResponse;
use App\Support\UploadStorage;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HealthController
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly UploadStorage $uploadStorage,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $dbOk = false;
        $migrationCount = 0;
        try {
            $dbOk = $this->pdo->query('SELECT 1') !== false;
            $stmt = $this->pdo->query('SELECT COUNT(*) AS c FROM migrations');
            $migrationCount = $stmt !== false ? (int) $stmt->fetch()['c'] : 0;
        } catch (\Throwable) {
            $dbOk = false;
        }

        $uploadWritable = $this->uploadStorage->isWritable();

        $status = $dbOk && $uploadWritable ? 'ok' : 'degraded';

        return JsonResponse::json($response, [
            'status' => $status,
            'database' => $dbOk ? 'ok' : 'error',
            'migrations_applied' => $migrationCount,
            'uploads_writable' => $uploadWritable,
        ], $status === 'ok' ? 200 : 503);
    }
}
