<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\TaskBoardService;
use App\Domain\TaskDuplicateTitleException;
use App\Domain\TaskVersionConflictException;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use App\Support\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class TaskController
{
    public function __construct(private readonly TaskBoardService $board)
    {
    }

    /** @param array<string, string> $args */
    public function store(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);

        try {
            $task = $this->board->createTask(
                CurrentUser::require($request),
                (int) $args['id'],
                (string) ($body['title'] ?? ''),
                isset($body['description']) ? (string) $body['description'] : null,
                isset($body['responsible']) ? (string) $body['responsible'] : null,
                isset($body['link']) ? (string) $body['link'] : null,
                false,
                (bool) ($body['allow_duplicate'] ?? false),
            );
        } catch (TaskDuplicateTitleException $e) {
            // Der Client fragt beim Nutzer nach und schickt die Anfrage bei
            // Bestätigung mit allow_duplicate erneut (FR-TASK-20).
            return JsonResponse::json($response, [
                'error' => [
                    'code' => 'DUPLICATE_TITLE',
                    'message' => $e->getMessage(),
                ],
                'existing' => self::serialize($e->existingTask),
            ], 409);
        }

        return JsonResponse::json($response, self::serialize($task), 201);
    }

    /** @param array<string, string> $args */
    public function import(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $tasks = $this->board->importTasks(
            CurrentUser::require($request),
            (int) $args['id'],
            (string) ($body['text'] ?? ''),
        );

        return JsonResponse::json($response, [
            'created' => count($tasks),
            'tasks' => array_map([self::class, 'serialize'], $tasks),
        ], 201);
    }

    /** @param array<string, string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);

        if (!isset($body['version']) || !is_int($body['version'])) {
            throw new ValidationException('Feld "version" fehlt oder ist keine Ganzzahl.');
        }
        $expectedVersion = $body['version'];
        unset($body['version']);

        try {
            $task = $this->board->updateTask(
                CurrentUser::require($request),
                (int) $args['id'],
                $body,
                $expectedVersion,
            );
        } catch (TaskVersionConflictException $e) {
            return JsonResponse::json($response, [
                'error' => [
                    'code' => 'VERSION_CONFLICT',
                    'message' => $e->getMessage(),
                ],
                'current' => self::serialize($e->currentTask),
            ], 409);
        }

        return JsonResponse::json($response, self::serialize($task));
    }

    /** @param array<string, string> $args */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->board->deleteTask(CurrentUser::require($request), (int) $args['id']);

        return $response->withStatus(204);
    }

    /** @param array<string, string> $args */
    public function duplicate(Request $request, Response $response, array $args): Response
    {
        $task = $this->board->duplicateTask(CurrentUser::require($request), (int) $args['id']);

        return JsonResponse::json($response, self::serialize($task), 201);
    }

    /** @param array<string, string> $args */
    public function move(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $task = $this->board->moveTask(
            CurrentUser::require($request),
            (int) $args['id'],
            (int) ($body['category_id'] ?? 0),
            (int) ($body['position'] ?? 0),
        );

        return JsonResponse::json($response, self::serialize($task));
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    public static function serialize(array $task): array
    {
        return [
            'id' => (int) $task['id'],
            'category_id' => (int) $task['category_id'],
            'title' => $task['title'],
            'description' => $task['description'],
            'responsible' => $task['responsible'],
            'link' => $task['link'],
            'position' => (int) $task['position'],
            'is_done' => ((int) $task['is_done']) === 1,
            'version' => (int) $task['version'],
            'due_date' => $task['due_date'],
            'priority' => $task['priority'],
            'created_at' => $task['created_at'],
            'updated_at' => $task['updated_at'],
        ];
    }
}
