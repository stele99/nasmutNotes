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
use App\Support\Cookie;
use App\Support\Env;
use App\Support\JsonResponse;
use App\Support\NotFoundException;
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

        // Zusätzliche, vom Ersteller gesetzte Einschränkung: Trotz öffentlichem
        // Modus (read/read_copy) verlangt diese Freigabe eine Anmeldung
        // (FR-SHR-05) - anders als bei write führt das nicht in den Workspace.
        if ($this->publicShares->requiresLogin($share) && !$user instanceof User) {
            return $this->render($request, $response, $token, $share, null, true);
        }

        if ($this->publicShares->requiresPassword($share) && !$this->isPasswordUnlocked($request, $share)) {
            return $this->renderUnlock($request, $response, $token, $share);
        }

        $this->publicShares->recordView($share);

        return $this->render($request, $response, $token, $share, $user instanceof User ? $user : null, false);
    }

    /**
     * Antwortet als JSON statt mit einem Redirect: Ein einfaches
     * `<form method="post">` ohne JavaScript könnte den von CsrfMiddleware
     * verlangten `X-CSRF-Token`-Header nicht mitschicken, sobald der Besucher
     * bereits als Nutzer angemeldet ist (Session-Cookie vorhanden). Das
     * Formular sendet deshalb per fetch(), wie schon der Kopieren-Knopf
     * daneben (siehe public-share.js).
     *
     * @param array<string, string> $args
     */
    public function unlock(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        $share = $this->publicShares->resolve($token);

        if (!$this->publicShares->requiresPassword($share)) {
            return JsonResponse::json($response, ['ok' => true]);
        }

        // NFR-SEC-07: Rate Limiting auf Passwortversuche von Share-Links.
        $rateKey = 'share-unlock:' . $share['share_id'] . ':' . RequestIp::hash($request);
        if (!$this->rateLimiter->attempt($rateKey, 10, 600)) {
            return JsonResponse::error($response, 'RATE_LIMITED', 'Zu viele Versuche. Bitte in ein paar Minuten erneut probieren.', 429);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $password = (string) ($body['password'] ?? '');

        if (!$this->publicShares->verifyPassword($share, $password)) {
            return JsonResponse::error($response, 'INVALID_PASSWORD', 'Falsches Kennwort.', 401);
        }

        return JsonResponse::json($response, ['ok' => true])
            ->withAddedHeader('Set-Cookie', $this->unlockCookie((int) $share['share_id'], (string) $share['password_hash']));
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
        $this->assertGate($request, $share);
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
        $token = (string) ($args['token'] ?? '');
        $this->assertGate($request, $this->publicShares->resolve($token));
        $image = $this->publicShares->image($token, (string) $args['imageToken']);
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
        $token = (string) ($args['token'] ?? '');
        $this->assertGate($request, $this->publicShares->resolve($token));
        $file = $this->publicShares->file($token, (int) $args['attachmentId']);
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

    /**
     * Für die Neben-Endpunkte (Bild, Datei, Kopieren): Wer die Sperre der
     * Hauptansicht (open()) nicht passiert hat, bekommt hier ohne Umweg über
     * ein Anmelde- oder Kennwortformular ein 404 - dieselbe Regel wie beim
     * Seitenzugriff sonst auch (FR-NOTE-22).
     *
     * @param array<string, mixed> $share
     */
    private function assertGate(Request $request, array $share): void
    {
        $user = $request->getAttribute('user');
        if ($this->publicShares->requiresLogin($share) && !$user instanceof User) {
            throw new NotFoundException('Freigabe nicht gefunden.');
        }
        if ($this->publicShares->requiresPassword($share) && !$this->isPasswordUnlocked($request, $share)) {
            throw new NotFoundException('Freigabe nicht gefunden.');
        }
    }

    /** @param array<string, mixed> $share */
    private function isPasswordUnlocked(Request $request, array $share): bool
    {
        $passwordHash = $share['password_hash'] ?? null;
        if (!is_string($passwordHash)) {
            return true;
        }

        $cookies = $request->getCookieParams();
        $cookieValue = $cookies[$this->unlockCookieName((int) $share['share_id'])] ?? null;
        if (!is_string($cookieValue) || $cookieValue === '') {
            return false;
        }

        return hash_equals($this->unlockSignature((int) $share['share_id'], $passwordHash), $cookieValue);
    }

    /**
     * Signiert mit APP_KEY statt server­seitig gespeichert zu werden, weil
     * anonyme Freigabe-Besucher keine eigene Session haben (nur angemeldete
     * Nutzer bekommen eine, siehe SessionService). Ändert sich das Kennwort,
     * ändert sich der Hash und damit automatisch auch die Signatur - alte
     * Freischaltungen verfallen dadurch von selbst.
     */
    private function unlockSignature(int $shareId, string $passwordHash): string
    {
        return hash_hmac('sha256', $shareId . '|' . $passwordHash, Env::get('APP_KEY', '') ?? '');
    }

    private function unlockCookieName(int $shareId): string
    {
        return 'share_unlock_' . $shareId;
    }

    private function unlockCookie(int $shareId, string $passwordHash): string
    {
        return Cookie::build(
            $this->unlockCookieName($shareId),
            $this->unlockSignature($shareId, $passwordHash),
            86400,
            Env::get('APP_ENV') === 'production',
            true,
            'Lax',
            '/s/',
        );
    }

    /** @param array<string, mixed> $share */
    private function renderUnlock(Request $request, Response $response, string $token, array $share): Response
    {
        $html = $this->renderer->page($request, 'share/unlock', [
            '_layout' => 'public_share_layout',
            'token' => $token,
            'page' => ['title' => $share['title'], 'type' => $share['type']],
        ], (string) $share['title']);
        $response->getBody()->write($html);

        return $this->privateResponse($response)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
