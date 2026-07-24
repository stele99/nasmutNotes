<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Auth\AuthException;
use App\Domain\Auth\AuthService;
use App\Domain\Auth\GooglePkceProvider;
use App\Domain\Auth\IdTokenVerifierInterface;
use App\Domain\Auth\InviteInvalidException;
use App\Domain\Auth\NoInviteException;
use App\Domain\Auth\OAuthFlight;
use App\Domain\SessionService;
use App\Repositories\AuditLogRepository;
use App\Support\Cookie;
use App\Support\Env;
use App\Support\RateLimiter;
use App\Support\Renderer;
use App\Support\RequestIp;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

final class AuthController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly SessionService $sessionService,
        private readonly IdTokenVerifierInterface $idTokenVerifier,
        private readonly RateLimiter $rateLimiter,
        private readonly Renderer $renderer,
        private readonly LoggerInterface $logger,
        private readonly AuditLogRepository $auditLog,
    ) {
    }

    public function google(Request $request, Response $response): Response
    {
        $ipHash = RequestIp::hash($request);
        if (!$this->rateLimiter->attempt("login:{$ipHash}", 20, 60)) {
            return $this->tooManyRequests($response);
        }

        $params = $request->getQueryParams();
        $inviteToken = isset($params['invite']) && is_string($params['invite']) ? $params['invite'] : null;

        $provider = $this->buildProvider();
        $nonce = bin2hex(random_bytes(16));

        $authUrl = $provider->getAuthorizationUrl([
            'nonce' => $nonce,
            'prompt' => 'select_account',
        ]);

        $flight = new OAuthFlight($provider->getState(), (string) $provider->getPkceCode(), $nonce, $inviteToken);
        $encoded = OAuthFlight::encode($flight, $this->appKey());

        return $response
            ->withStatus(302)
            ->withHeader('Location', $authUrl)
            ->withAddedHeader('Set-Cookie', Cookie::build(
                OAuthFlight::COOKIE_NAME,
                $encoded,
                OAuthFlight::TTL_SECONDS,
                $this->isProduction(),
            ));
    }

    public function callback(Request $request, Response $response): Response
    {
        $ipHash = RequestIp::hash($request);
        if (!$this->rateLimiter->attempt("login:{$ipHash}", 20, 60)) {
            return $this->tooManyRequests($response);
        }

        $params = $request->getQueryParams();
        $cookies = $request->getCookieParams();
        $flightCookie = $cookies[OAuthFlight::COOKIE_NAME] ?? null;

        $flight = is_string($flightCookie) ? OAuthFlight::decode($flightCookie, $this->appKey()) : null;
        if ($flight === null) {
            return $this->redirectToLogin($request, $response, 'Die Anmeldung ist abgelaufen. Bitte erneut versuchen.');
        }

        if (isset($params['error'])) {
            return $this->redirectToLogin($request, $response, 'Die Anmeldung wurde abgebrochen.');
        }

        $state = is_string($params['state'] ?? null) ? $params['state'] : '';
        if ($state === '' || !hash_equals($flight->state, $state)) {
            return $this->redirectToLogin($request, $response, 'Ungültiger Anmeldevorgang (State-Mismatch).');
        }

        $code = is_string($params['code'] ?? null) ? $params['code'] : '';
        if ($code === '') {
            return $this->redirectToLogin($request, $response, 'Kein Autorisierungscode erhalten.');
        }

        $provider = $this->buildProvider();
        $provider->setPkceCode($flight->codeVerifier);

        try {
            $token = $provider->getAccessToken('authorization_code', ['code' => $code]);
            $idToken = $token->getValues()['id_token'] ?? null;
            if (!is_string($idToken)) {
                throw new AuthException('Kein ID-Token in der Google-Antwort enthalten.');
            }

            $claims = $this->idTokenVerifier->verify($idToken, $flight->nonce);
            $user = $this->authService->loginOrRegister($claims, $flight->inviteToken);
        } catch (NoInviteException) {
            return $this->redirectToLogin(
                $request,
                $response,
                'Für dieses Konto liegt keine Einladung vor. Bitte einen gültigen Invite-Link verwenden.',
            );
        } catch (InviteInvalidException $e) {
            return $this->redirectToLogin($request, $response, 'Der Invite-Link ist ungültig: ' . $e->reason);
        } catch (AuthException $e) {
            $this->logger->warning('auth_failed', ['reason' => $e->getMessage()]);

            return $this->redirectToLogin($request, $response, 'Die Anmeldung ist fehlgeschlagen. Bitte erneut versuchen.');
        }

        if (!$user->isActive) {
            return $this->redirectToLogin($request, $response, 'Dieses Konto wurde deaktiviert.');
        }

        $userAgent = $request->getHeaderLine('User-Agent') ?: null;
        $rawSessionToken = $this->sessionService->start($user->id, $userAgent, $ipHash);

        $this->logger->info('user_login', ['user_id' => $user->id]);
        $this->auditLog->log($user->id, 'login', 'user', $user->id, $ipHash);

        if ($flight->inviteToken !== null) {
            $this->auditLog->log($user->id, 'invite_redeemed', 'user', $user->id, $ipHash);
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', '/app')
            ->withAddedHeader('Set-Cookie', Cookie::expire(OAuthFlight::COOKIE_NAME, $this->isProduction()))
            ->withAddedHeader('Set-Cookie', Cookie::build(
                SessionService::COOKIE_NAME,
                $rawSessionToken,
                Env::int('SESSION_LIFETIME_DAYS', 30) * 86400,
                $this->isProduction(),
            ));
    }

    public function logout(Request $request, Response $response): Response
    {
        $cookies = $request->getCookieParams();
        $token = $cookies[SessionService::COOKIE_NAME] ?? null;
        if (is_string($token) && $token !== '') {
            $this->sessionService->logout($token);
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', '/')
            ->withAddedHeader('Set-Cookie', Cookie::expire(SessionService::COOKIE_NAME, $this->isProduction()));
    }

    private function buildProvider(): GooglePkceProvider
    {
        return new GooglePkceProvider([
            'clientId' => Env::get('GOOGLE_CLIENT_ID', ''),
            'clientSecret' => Env::get('GOOGLE_CLIENT_SECRET', ''),
            'redirectUri' => Env::get('GOOGLE_REDIRECT_URI', ''),
            'hostedDomain' => Env::get('GOOGLE_HOSTED_DOMAIN') ?: null,
        ]);
    }

    private function redirectToLogin(Request $request, Response $response, string $error): Response
    {
        $html = $this->renderer->page($request, 'login', ['error' => $error], 'Anmeldung');
        $response->getBody()->write($html);

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withAddedHeader('Set-Cookie', Cookie::expire(OAuthFlight::COOKIE_NAME, $this->isProduction()));
    }

    private function tooManyRequests(Response $response): Response
    {
        $response->getBody()->write('Zu viele Anmeldeversuche. Bitte kurz warten.');

        return $response->withStatus(429)->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    private function appKey(): string
    {
        return Env::get('APP_KEY', '') ?? '';
    }

    private function isProduction(): bool
    {
        return Env::get('APP_ENV') === 'production';
    }
}
