<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\ShareService;
use App\Support\CurrentUser;
use App\Support\Env;
use App\Support\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ShareController
{
    public function __construct(private readonly ShareService $shares)
    {
    }

    /** @param array<string, string> $args */
    public function open(Request $request, Response $response, array $args): Response
    {
        $share = $this->shares->open(CurrentUser::require($request), (string) $args['token']);

        return $response
            ->withStatus(302)
            ->withHeader('Location', '/app/page/' . (int) $share['page_id']);
    }

    /** @param array<string, string> $args */
    public function index(Request $request, Response $response, array $args): Response
    {
        $shares = $this->shares->listForPage(CurrentUser::require($request), (int) $args['id']);

        return JsonResponse::json($response, ['shares' => array_map([$this, 'serialize'], $shares)]);
    }

    /** @param array<string, string> $args */
    public function writers(Request $request, Response $response, array $args): Response
    {
        $writers = $this->shares->listAcceptedWriters(CurrentUser::require($request), (int) $args['id']);

        return JsonResponse::json($response, ['writers' => array_map(
            static fn (array $writer): array => [
                'id' => (int) $writer['id'],
                'name' => (string) $writer['name'],
                'is_owner' => (bool) $writer['is_owner'],
            ],
            $writers,
        )]);
    }

    /** @param array<string, string> $args */
    public function collaborators(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $collaborators = $this->shares->listCollaborators($user, (int) $args['id']);

        return JsonResponse::json($response, [
            'collaborators' => array_map(
                static fn (array $person): array => [
                    'id' => (int) $person['id'],
                    'name' => (string) $person['name'],
                    'is_owner' => (bool) $person['is_owner'],
                    'is_me' => (int) $person['id'] === $user->id,
                ],
                $collaborators,
            ),
            // Für den Filter „nur meine Aufgaben": der eigene Name steht auch
            // dann bereit, wenn die Seite (noch) nicht geteilt ist.
            'me' => ['id' => $user->id, 'name' => $user->name],
        ]);
    }

    /** @param array<string, string> $args */
    public function store(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $share = $this->shares->create(
            CurrentUser::require($request),
            (int) $args['id'],
            (string) ($body['permission'] ?? ''),
        );

        return JsonResponse::json($response, [
            'id' => $share['id'],
            'permission' => $share['permission'],
            'url' => $this->url($request, $share['token']),
            'created_at' => $share['created_at'],
        ], 201);
    }

    /** @param array<string, string> $args */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->shares->revoke(CurrentUser::require($request), (int) $args['id']);

        return $response->withStatus(204);
    }

    /** @param array<string, string> $args */
    public function stop(Request $request, Response $response, array $args): Response
    {
        $this->shares->revokeAll(CurrentUser::require($request), (int) $args['id']);

        return $response->withStatus(204);
    }

    /** @param array<string, string> $args */
    public function leave(Request $request, Response $response, array $args): Response
    {
        $this->shares->leave(CurrentUser::require($request), (int) $args['id']);

        return $response->withStatus(204);
    }

    /**
     * @param array<string, mixed> $share
     * @return array<string, mixed>
     */
    private function serialize(array $share): array
    {
        return [
            'id' => (int) $share['id'],
            'permission' => $share['permission'],
            'expires_at' => $share['expires_at'],
            'last_accessed_at' => $share['last_accessed_at'],
            'access_count' => (int) $share['access_count'],
            'created_at' => $share['created_at'],
        ];
    }

    private function url(Request $request, string $token): string
    {
        $baseUrl = rtrim((string) (Env::get('APP_URL') ?? ''), '/');
        if ($baseUrl === '') {
            $uri = $request->getUri();
            $baseUrl = $uri->getScheme() . '://' . $uri->getAuthority();
        }

        return $baseUrl . '/s/' . rawurlencode($token);
    }
}
