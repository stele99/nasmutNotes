<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Notes\NoteRewriteService;
use App\Domain\Voice\VoiceServiceException;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use App\Support\RateLimiter;
use App\Support\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

final class NoteAiController
{
    public function __construct(
        private readonly NoteRewriteService $rewriter,
        private readonly RateLimiter $rateLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param array<string, string> $args */
    public function rewrite(Request $request, Response $response, array $args): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        if (!is_array($body['content'] ?? null)) {
            throw new ValidationException('Der Notizinhalt fehlt.');
        }

        $user = CurrentUser::require($request);
        if (!$this->rateLimiter->attempt("note-ai:{$user->id}", 10, 300)) {
            return JsonResponse::error(
                $response->withHeader('Retry-After', '60'),
                'RATE_LIMITED',
                'Zu viele KI-Anfragen. Bitte kurz warten.',
                429,
            );
        }

        try {
            $result = $this->rewriter->rewrite(
                $user,
                (int) $args['id'],
                $body['content'],
                is_string($body['mode'] ?? null) ? $body['mode'] : '',
            );
        } catch (VoiceServiceException $e) {
            $this->logger->warning('KI-Textüberarbeitung fehlgeschlagen', [
                'user_id' => $user->id,
                'page_id' => (int) $args['id'],
                'message' => $e->getMessage(),
            ]);

            return JsonResponse::error(
                $response,
                'AI_SERVICE_FAILED',
                'Der KI-Dienst konnte keinen Vorschlag erstellen. Die Notiz wurde nicht verändert.',
                502,
            );
        }

        return JsonResponse::json($response, $result);
    }
}
