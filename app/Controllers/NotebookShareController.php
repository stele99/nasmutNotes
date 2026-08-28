<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\NotebookShareService;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class NotebookShareController
{
    public function __construct(private readonly NotebookShareService $shares)
    {
    }

    /** @param array<string, string> $args */
    public function index(Request $request, Response $response, array $args): Response
    {
        $participants = $this->shares->listParticipants(CurrentUser::require($request), (int) $args['id']);

        return JsonResponse::json($response, ['participants' => array_map([$this, 'serialize'], $participants)]);
    }

    /** @param array<string, string> $args */
    public function store(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $participant = $this->shares->share(
            CurrentUser::require($request),
            (int) $args['id'],
            (string) ($body['email'] ?? ''),
        );

        return JsonResponse::json($response, $this->serialize($participant), 201);
    }

    /** @param array<string, string> $args */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->shares->removeParticipant(CurrentUser::require($request), (int) $args['id'], (int) $args['userId']);

        return $response->withStatus(204);
    }

    /** @param array<string, string> $args */
    public function leave(Request $request, Response $response, array $args): Response
    {
        $this->shares->leave(CurrentUser::require($request), (int) $args['id']);

        return $response->withStatus(204);
    }

    /**
     * @param array<string, mixed> $participant
     * @return array<string, mixed>
     */
    private function serialize(array $participant): array
    {
        return [
            'id' => (int) $participant['id'],
            'name' => (string) $participant['name'],
            'email' => (string) $participant['email'],
            'created_at' => $participant['created_at'],
        ];
    }
}
