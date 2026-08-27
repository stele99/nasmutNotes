<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Assistant\AssistantService;
use App\Domain\Assistant\AssistantServiceException;
use App\Domain\Assistant\UpstreamReply;
use App\Domain\Auth\DevicePairingService;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use App\Support\RateLimiter;
use App\Support\Renderer;
use App\Support\RequestIp;
use App\Support\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * Schnittstelle für den Desktop-Assistant (OpenAI-förmig, Auth per
 * Automations-Token) und die Pairing-Endpunkte für die Erstverbindung.
 *
 * Fehler an den Client gehen als OpenAI-Error-Objekt raus, damit
 * OpenAI-SDKs sie ohne Sonderbehandlung verstehen. Fehler des
 * KI-Dienstes selbst werden unverändert durchgereicht.
 */
final class AssistantController
{
    public function __construct(
        private readonly AssistantService $assistant,
        private readonly DevicePairingService $pairing,
        private readonly RateLimiter $rateLimiter,
        private readonly LoggerInterface $logger,
        private readonly Renderer $renderer,
    ) {
    }

    /** Ping und Identitätsprüfung: Wer durchkommt, ist verbunden. */
    public function me(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);

        return JsonResponse::json($response, [
            'user' => ['name' => $user->name, 'email' => $user->email],
            'assistant' => ['usable' => $this->assistant->isUsable()],
        ]);
    }

    public function chat(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);
        if (!$this->rateLimiter->attempt("assistant-chat:{$user->id}", 60, 300)) {
            return $this->openAiError($response, 429, 'Zu viele Anfragen. Bitte kurz warten.', 'rate_limit_error');
        }

        $size = $request->getBody()->getSize();
        if ($size !== null && $size > AssistantService::MAX_PAYLOAD_BYTES) {
            return $this->openAiError($response, 413, 'Die Anfrage ist zu groß.', 'invalid_request_error');
        }

        $payload = json_decode((string) $request->getBody(), true);
        if (!is_array($payload)) {
            return $this->openAiError($response, 400, 'Der Anfragekörper muss ein JSON-Objekt sein.', 'invalid_request_error');
        }

        try {
            $reply = $this->assistant->chat($user, $payload);
        } catch (AssistantServiceException $e) {
            $this->logger->error('Assistant-Proxy nicht erreichbar', ['message' => $e->getMessage()]);

            return $this->openAiError($response, 502, $e->getMessage(), 'api_error');
        } catch (ValidationException $e) {
            return $this->openAiError($response, 503, $e->getMessage(), 'server_error');
        }

        return $this->forward($response, $reply);
    }

    public function transcribe(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);
        if (!$this->rateLimiter->attempt("assistant-transcribe:{$user->id}", 20, 300)) {
            return $this->openAiError($response, 429, 'Zu viele Transkriptionen. Bitte kurz warten.', 'rate_limit_error');
        }

        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof \Psr\Http\Message\UploadedFileInterface) {
            return $this->openAiError($response, 400, 'Feld "file" mit der Aufnahme fehlt (multipart/form-data).', 'invalid_request_error');
        }

        try {
            $result = $this->assistant->transcribe($user, $file);
        } catch (ValidationException $e) {
            return $this->openAiError($response, 400, $e->getMessage(), 'invalid_request_error');
        }

        $forward = (new SlimResponse())
            ->withStatus($result['status'])
            ->withHeader('Content-Type', $result['contentType'] !== '' ? $result['contentType'] : 'application/json')
            ->withHeader('Cache-Control', 'no-store');
        $forward->getBody()->write($result['body']);

        return $forward;
    }

    /** Startet eine Pairing-Sitzung (öffentlich, IP- begrenzt). */
    public function startPair(Request $request, Response $response): Response
    {
        if (!$this->rateLimiter->attempt('assistant-pair:' . RequestIp::hash($request), 10, 300)) {
            return JsonResponse::error($response, 'RATE_LIMITED', 'Zu viele Paarungsversuche. Bitte kurz warten.', 429);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $result = $this->pairing->start(
            (string) ($body['label'] ?? ''),
            (string) ($body['client_id'] ?? ''),
            isset($body['platform']) ? (string) $body['platform'] : null,
        );

        return JsonResponse::json($response, $result, 201);
    }

    /** Abholung durch den Client (öffentlich, IP- und Code-begrenzt). */
    public function pollPair(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $deviceCode = (string) ($body['device_code'] ?? '');

        if (!$this->rateLimiter->attempt('assistant-poll:' . RequestIp::hash($request), 60, 300)
            || !$this->rateLimiter->attempt('assistant-poll-code:' . hash('sha256', $deviceCode), 30, 300)) {
            return JsonResponse::error($response, 'RATE_LIMITED', 'Zu viele Abfragen. Bitte kurz warten.', 429);
        }

        if ($deviceCode === '') {
            throw new ValidationException('Feld "device_code" fehlt.');
        }

        return JsonResponse::json($response, $this->pairing->poll($deviceCode));
    }

    /** Bestätigung durch den angemeldeten Nutzer (Session + CSRF). */
    public function approvePair(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $this->pairing->approve($user, (string) ($body['user_code'] ?? ''), RequestIp::hash($request));

        return JsonResponse::json($response, ['approved' => true]);
    }

    /**
     * Bestätigungsseite, die der Desktop-Assistant im Browser öffnet. Der
     * Code steht in der URL - nur der kurze Anzeige-Code, nie der Token.
     */
    public function pairPage(Request $request, Response $response): Response
    {
        $code = (string) ($request->getQueryParams()['code'] ?? '');
        $description = $this->pairing->describeByUserCode($code);

        $html = $this->renderer->page($request, 'assistant/pair', [
            'pairCode' => $code,
            'pairLabel' => $description['label'] ?? null,
            'pairPlatform' => $description['platform'] ?? null,
        ], 'Desktop-Assistant verbinden');
        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function forward(Response $response, UpstreamReply $reply): Response
    {
        $forward = (new SlimResponse())
            ->withStatus($reply->status)
            ->withHeader('Content-Type', $reply->contentType !== '' ? $reply->contentType : 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Accel-Buffering', 'no');
        $forward = $forward->withBody($reply->body);

        return $forward;
    }

    private function openAiError(Response $response, int $status, string $message, string $type): Response
    {
        $payload = ['error' => ['message' => $message, 'type' => $type]];

        return JsonResponse::json($response, $payload, $status)
            ->withHeader('Cache-Control', 'no-store');
    }
}
