<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Notes\NoteEncryptionException;
use App\Domain\Voice\VoiceNoteService;
use App\Domain\Voice\VoiceServiceException;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use App\Support\RateLimiter;
use App\Support\RequestIp;
use App\Support\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/**
 * Sprachnotizen: Aufnahme hochladen, transkribieren lassen und entweder als
 * Text zurückgeben (Diktat in eine offene Notiz) oder direkt als neue Notiz
 * anlegen (FR-VOICE-01..06).
 */
final class VoiceNoteController
{
    public function __construct(
        private readonly VoiceNoteService $voice,
        private readonly RateLimiter $rateLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Zustand der Funktion für die Oberfläche (Knopf anzeigen oder nicht). */
    public function config(Request $request, Response $response): Response
    {
        $settings = $this->voice->settings();

        return JsonResponse::json($response, [
            'enabled' => $settings->isUsable(),
            'max_seconds' => $settings->maxSeconds,
            'max_bytes' => $settings->maxBytes(),
        ])->withHeader('Cache-Control', 'private, no-store');
    }

    /** Transkribiert eine Aufnahme, ohne etwas zu speichern. */
    public function transcribe(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);
        $limited = $this->assertWithinLimit($response, $user->id);
        if ($limited !== null) {
            return $limited;
        }

        return $this->guard($response, function () use ($request, $user, $response): Response {
            $body = (array) ($request->getParsedBody() ?? []);
            $rawPageId = $body['page_id'] ?? null;
            if (!is_int($rawPageId) && !(is_string($rawPageId) && ctype_digit($rawPageId))) {
                throw new ValidationException('Feld "page_id" fehlt oder ist ungültig.');
            }
            $result = $this->voice->transcribeForPage($user, (int) $rawPageId, $this->requireAudio($request));

            return JsonResponse::json($response, [
                'transcript' => $result['transcript'],
                'markdown' => $result['markdown'],
                'title' => $result['title'],
                'notebook_id' => $result['notebook_id'],
                'notebook_name' => $result['notebook_name'],
                'document' => $result['document'],
            ]);
        });
    }

    /** Legt aus einer Aufnahme direkt eine neue Notiz an. */
    public function store(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);
        $limited = $this->assertWithinLimit($response, $user->id);
        if ($limited !== null) {
            return $limited;
        }

        return $this->guard($response, function () use ($request, $user, $response): Response {
            $result = $this->voice->createNote(
                $user,
                $this->requireAudio($request),
                RequestIp::hash($request),
                $this->locationFrom($request),
            );
            $page = $result['page'];

            return JsonResponse::json($response, [
                'page' => [
                    'id' => (int) $page['id'],
                    'type' => (string) $page['type'],
                    'title' => (string) $page['title'],
                    'notebook_id' => $page['notebook_id'] !== null ? (int) $page['notebook_id'] : null,
                    'notebook_name' => $page['notebook_name'] ?? null,
                    'notebook_icon' => $page['notebook_icon'] ?? null,
                    'notebook_color' => $page['notebook_color'] ?? null,
                    'is_encrypted' => false,
                    'is_shared' => false,
                    'can_edit' => true,
                    'updated_at' => $page['updated_at'] ?? null,
                    'location' => PageController::serializeLocation($page),
                ],
                'transcript' => $result['transcript'],
                'notebook_name' => $result['notebook_name'],
            ], 201);
        });
    }

    /**
     * Aufnahmeort aus den Formularfeldern neben der Audiodatei; fehlt er oder
     * ist er unbrauchbar, entsteht die Notiz einfach ohne ihn (FR-NOTE-25).
     *
     * @return array{lat: string, lon: string, accuracy: string}|null
     */
    private function locationFrom(Request $request): ?array
    {
        $body = (array) ($request->getParsedBody() ?? []);
        if (!isset($body['lat'], $body['lon'])) {
            return null;
        }

        return [
            'lat' => (string) $body['lat'],
            'lon' => (string) $body['lon'],
            'accuracy' => (string) ($body['accuracy'] ?? ''),
        ];
    }

    private function requireAudio(Request $request): UploadedFileInterface
    {
        $file = $request->getUploadedFiles()['audio'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            throw new ValidationException('Es wurde keine Aufnahme übermittelt.');
        }

        return $file;
    }

    /**
     * Jeder Aufruf kostet Geld beim Anbieter und dauert; ein enges Limit je
     * Nutzer hält beides im Rahmen.
     */
    private function assertWithinLimit(Response $response, int $userId): ?Response
    {
        if ($this->rateLimiter->attempt("voice-transcribe:{$userId}", 20, 300)) {
            return null;
        }

        return JsonResponse::error(
            $response->withHeader('Retry-After', '60'),
            'RATE_LIMITED',
            'Zu viele Sprachnotizen in kurzer Zeit. Bitte kurz warten.',
            429,
        );
    }

    /**
     * Störungen beim Anbieter sind kein Serverfehler dieser Anwendung: Sie
     * bekommen einen eigenen Code, damit die Oberfläche sie als solche melden
     * kann. Die Meldung selbst enthält nie den API-Schlüssel.
     */
    private function guard(Response $response, callable $action): Response
    {
        // Transkription und Nachbearbeitung brauchen zusammen deutlich mehr als
        // die üblichen 30 Sekunden.
        set_time_limit(300);

        try {
            /** @var Response $result */
            $result = $action();

            return $result;
        } catch (NoteEncryptionException $e) {
            return JsonResponse::error($response, $e->errorCode, $e->getMessage(), $e->status);
        } catch (VoiceServiceException $e) {
            $this->logger->warning('Sprachdienst fehlgeschlagen', ['message' => $e->getMessage()]);

            return JsonResponse::error($response, 'VOICE_SERVICE_FAILED', $e->getMessage(), 502);
        }
    }
}
