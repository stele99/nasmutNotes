<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\NotebookService;
use App\Domain\Notes\NoteEncryptionException;
use App\Domain\PageCopyService;
use App\Domain\PublicShareService;
use App\Domain\ShareService;
use App\Domain\User;
use App\Repositories\AuditLogRepository;
use App\Support\JsonResponse;
use App\Support\RateLimiter;
use App\Support\Renderer;
use App\Support\RequestIp;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

final class PublicShareController
{
    public function __construct(
        private readonly PublicShareService $publicShares,
        private readonly ShareService $shares,
        private readonly PageCopyService $copies,
        private readonly NotebookService $notebooks,
        private readonly AuditLogRepository $auditLog,
        private readonly RateLimiter $rateLimiter,
        private readonly Renderer $renderer,
    ) {
    }

    /** @param array<string, string> $args */
    public function open(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        $share = $this->publicShares->resolve($token);
        $user = $request->getAttribute('user');

        if (($share['mode'] ?? null) === 'write') {
            if (!$user instanceof User) {
                return $this->render($request, $response, $token, $share, null, true);
            }
            $accepted = $this->shares->open($user, $token);

            return $response->withStatus(302)->withHeader('Location', '/app/page/' . (int) $accepted['page_id']);
        }

        $this->publicShares->recordView($share);

        return $this->render($request, $response, $token, $share, $user instanceof User ? $user : null, false);
    }

    /** @param array<string, string> $args */
    public function copy(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$user instanceof User) {
            return JsonResponse::error($response, 'UNAUTHORIZED', 'Anmeldung erforderlich.', 401);
        }
        $token = (string) ($args['token'] ?? '');
        $share = $this->publicShares->resolve($token);
        $body = (array) ($request->getParsedBody() ?? []);
        $notebookId = $body['notebook_id'] ?? null;
        $notebookId = $notebookId === null || $notebookId === '' ? null : (int) $notebookId;
        if (!$this->rateLimiter->attempt("share-copy:{$user->id}:" . hash('sha256', $token), 10, 3600)) {
            return JsonResponse::error($response, 'RATE_LIMITED', 'Zu viele Kopien. Bitte später erneut versuchen.', 429);
        }

        try {
            $copy = $this->copies->copyFromShare($user, $share, $notebookId);
        } catch (NoteEncryptionException $e) {
            return JsonResponse::error($response, $e->errorCode, $e->getMessage(), $e->status);
        }
        $this->auditLog->log($user->id, 'shared_page_copied', 'page', (int) $copy['id'], RequestIp::hash($request), [
            'source_page_id' => (int) $share['page_id'],
        ]);

        return JsonResponse::json($response, ['page_id' => (int) $copy['id'], 'url' => '/app/page/' . (int) $copy['id']], 201);
    }

    /** @param array<string, string> $args */
    public function image(Request $request, Response $response, array $args): Response
    {
        $image = $this->publicShares->image((string) $args['token'], (string) $args['imageToken']);
        $resource = fopen($image['path'], 'rb');
        if ($resource === false) {
            throw new \RuntimeException('Das Bild konnte nicht geöffnet werden.');
        }

        return $this->privateResponse($response->withBody(new Stream($resource)))
            ->withHeader('Content-Type', $image['mime_type'])
            ->withHeader('Content-Length', (string) $image['byte_size'])
            ->withHeader('Content-Disposition', 'inline');
    }

    /** @param array<string, string> $args */
    public function file(Request $request, Response $response, array $args): Response
    {
        $file = $this->publicShares->file((string) $args['token'], (int) $args['attachmentId']);
        $resource = fopen($file['path'], 'rb');
        if ($resource === false) {
            throw new \RuntimeException('Die Datei konnte nicht geöffnet werden.');
        }

        return $this->privateResponse($response->withBody(new Stream($resource)))
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Length', (string) $file['byte_size'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . addcslashes($file['original_name'], '"\\') . '"');
    }

    /** @param array<string, mixed> $share */
    private function render(Request $request, Response $response, string $token, array $share, ?User $user, bool $loginRequired): Response
    {
        $view = $loginRequired ? ['share' => $share, 'page' => ['title' => $share['title'], 'type' => $share['type']]] : $this->publicShares->view($token);
        $html = $this->renderer->page($request, 'share/public', array_merge($view, [
            '_layout' => 'public_share_layout',
            'token' => $token,
            'loginRequired' => $loginRequired,
            'authenticated' => $user !== null,
            'notebooks' => $user !== null && ($share['mode'] ?? null) === 'read_copy' ? $this->notebooks->list($user) : [],
            'loginUrl' => '/auth/google?return=' . rawurlencode('/s/' . $token),
        ]), (string) $share['title']);
        $response->getBody()->write($html);

        return $this->privateResponse($response)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function privateResponse(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
