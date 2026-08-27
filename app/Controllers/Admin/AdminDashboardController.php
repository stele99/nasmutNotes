<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Domain\AdminService;
use App\Domain\Ai\AiModelSettings;
use App\Domain\Ai\AiUsageService;
use App\Domain\Assistant\AssistantService;
use App\Domain\Notes\NoteRewriteService;
use App\Domain\Voice\VoiceNoteService;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use App\Support\RateLimiter;
use App\Support\Renderer;
use App\Support\RequestIp;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin-Dashboard: Nutzer samt Speicherbedarf, Kontingente, Löschen eines
 * Nutzers mit allen Daten und Aufräumen verwaister Bilder (FR-ADM-01..06).
 */
final class AdminDashboardController
{
    public function __construct(
        private readonly AdminService $admin,
        private readonly Renderer $renderer,
        private readonly RateLimiter $rateLimiter,
        private readonly VoiceNoteService $voice,
        private readonly NoteRewriteService $rewriter,
        private readonly AiUsageService $aiUsage,
        private readonly AssistantService $assistant,
        private readonly AiModelSettings $aiModelSettings,
    ) {
    }

    public function page(Request $request, Response $response): Response
    {
        $html = $this->renderer->page($request, 'admin/dashboard', [], 'Admin · Übersicht');
        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function aiPage(Request $request, Response $response): Response
    {
        $html = $this->renderer->page($request, 'admin/ai', [], 'Admin · KI-Einstellungen');
        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function overview(Request $request, Response $response): Response
    {
        return JsonResponse::json($response, array_merge($this->admin->overview(), [
            'ai_defaults' => $this->aiModelSettings->toAdminArray(),
            'voice' => $this->voice->adminSettings(),
            'note_ai' => $this->rewriter->adminSettings(),
            'assistant' => $this->assistant->settings()->toAdminArray(),
            'ai_costs' => $this->aiUsage->costs(),
        ]));
    }

    /** Gemeinsame KI-Defaults: Ein LLM für alle Bereiche + Reasoning-Vorgabe. */
    public function updateAiDefaults(Request $request, Response $response): Response
    {
        $defaults = $this->aiModelSettings->setDefaults(
            CurrentUser::require($request),
            (array) ($request->getParsedBody() ?? []),
            RequestIp::hash($request),
        );

        return JsonResponse::json($response, ['ai_defaults' => $defaults]);
    }

    /** Desktop-Assistant: Freischaltung, Modell und Ziel-Endpoint. */
    public function updateAssistantSettings(Request $request, Response $response): Response
    {
        $settings = $this->assistant->updateSettings(
            CurrentUser::require($request),
            (array) ($request->getParsedBody() ?? []),
            RequestIp::hash($request),
        );

        return JsonResponse::json($response, ['assistant' => $settings->toAdminArray()]);
    }

    /** KI-Verbrauch über alle Nutzer: Tokens und Kosten. */
    public function aiUsage(Request $request, Response $response): Response
    {
        return JsonResponse::json($response, $this->aiUsage->adminOverview());
    }

    public function modelCosts(Request $request, Response $response): Response
    {
        return JsonResponse::json($response, ['costs' => $this->aiUsage->costs()]);
    }

    public function storeModelCost(Request $request, Response $response): Response
    {
        $cost = $this->aiUsage->setCost(
            CurrentUser::require($request),
            (array) ($request->getParsedBody() ?? []),
            RequestIp::hash($request),
        );

        return JsonResponse::json($response, ['cost' => $cost], 201);
    }

    /** @param array<string, string> $args */
    public function destroyModelCost(Request $request, Response $response, array $args): Response
    {
        $this->aiUsage->removeCost(
            CurrentUser::require($request),
            (string) ($args['model'] ?? ''),
            RequestIp::hash($request),
        );

        return $response->withStatus(204);
    }

    /** Sprachnotizen: Freischaltung, Modelle und Anweisung (FR-VOICE-05). */
    public function updateVoiceSettings(Request $request, Response $response): Response
    {
        $admin = CurrentUser::require($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $settings = $this->voice->updateSettings($admin, $body, RequestIp::hash($request));

        return JsonResponse::json($response, ['voice' => $settings->toAdminArray()]);
    }

    public function updateNoteAiSettings(Request $request, Response $response): Response
    {
        $settings = $this->rewriter->updateSettings(
            CurrentUser::require($request),
            (array) ($request->getParsedBody() ?? []),
            RequestIp::hash($request),
        );

        return JsonResponse::json($response, ['note_ai' => $settings]);
    }

    /** @param array<string, string> $args */
    public function destroyUser(Request $request, Response $response, array $args): Response
    {
        $admin = CurrentUser::require($request);
        $result = $this->admin->deleteUser($admin, (int) ($args['id'] ?? 0), RequestIp::hash($request));

        return JsonResponse::json($response, $result);
    }

    /** @param array<string, string> $args */
    public function updateUserQuota(Request $request, Response $response, array $args): Response
    {
        $admin = CurrentUser::require($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $raw = $body['storage_quota_mb'] ?? null;

        $quota = $this->admin->setUserQuotaMb(
            $admin,
            (int) ($args['id'] ?? 0),
            $raw === null || $raw === '' ? null : (int) $raw,
            RequestIp::hash($request),
        );

        return JsonResponse::json($response, ['storage_quota_mb' => $quota]);
    }

    public function updateDefaultQuota(Request $request, Response $response): Response
    {
        $admin = CurrentUser::require($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $quota = $this->admin->setDefaultQuotaMb(
            $admin,
            (int) ($body['default_quota_mb'] ?? 0),
            RequestIp::hash($request),
        );

        return JsonResponse::json($response, ['default_quota_mb' => $quota]);
    }

    public function updateMaxAttachment(Request $request, Response $response): Response
    {
        $admin = CurrentUser::require($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $maxMb = $this->admin->setMaxAttachmentMb(
            $admin,
            (int) ($body['max_attachment_mb'] ?? 0),
            RequestIp::hash($request),
        );

        return JsonResponse::json($response, ['max_attachment_mb' => $maxMb]);
    }

    /** Offline-Limit je Anhang in KB (FR-OFFLINE-06). */
    public function updateOfflineAttachmentLimit(Request $request, Response $response): Response
    {
        $admin = CurrentUser::require($request);
        $body = (array) ($request->getParsedBody() ?? []);
        $maxKb = $this->admin->setOfflineAttachmentMaxKb(
            $admin,
            (int) ($body['offline_attachment_max_kb'] ?? 0),
            RequestIp::hash($request),
        );

        return JsonResponse::json($response, ['offline_attachment_max_kb' => $maxKb]);
    }

    public function purgeOrphans(Request $request, Response $response): Response
    {
        $admin = CurrentUser::require($request);
        $result = $this->admin->purgeOrphanedAttachments($admin, RequestIp::hash($request));

        return JsonResponse::json($response, $result);
    }

    /** @param array<string, string> $args */
    public function compressUserImages(Request $request, Response $response, array $args): Response
    {
        $admin = CurrentUser::require($request);
        if (!$this->rateLimiter->attempt("admin-image-compress:{$admin->id}", 3, 300)) {
            return JsonResponse::error(
                $response,
                'RATE_LIMITED',
                'Zu viele Kompressionsläufe. Bitte kurz warten.',
                429,
            );
        }
        set_time_limit(600);
        $result = $this->admin->compressUserImages(
            $admin,
            (int) ($args['id'] ?? 0),
            RequestIp::hash($request),
        );

        return JsonResponse::json($response, $result);
    }
}
