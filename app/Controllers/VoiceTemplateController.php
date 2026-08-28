<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Voice\VoiceTemplateService;
use App\Support\CurrentUser;
use App\Support\JsonResponse;
use App\Support\RequestIp;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Persönliche Diktier-Vorlagen: sichtbar und änderbar sind ausschließlich
 * die eigenen. Globale Vorlagen verwaltet der Admin über
 * Admin\AdminDashboardController.
 */
final class VoiceTemplateController
{
    public function __construct(private readonly VoiceTemplateService $templates)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);

        return JsonResponse::json($response, ['voice_templates' => $this->templates->listOwn($user)]);
    }

    public function store(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);
        $body = (array) ($request->getParsedBody() ?? []);

        $template = $this->templates->create(
            $user,
            (string) ($body['name'] ?? ''),
            (string) ($body['instruction'] ?? ''),
            (string) ($body['vocabulary'] ?? ''),
            RequestIp::hash($request),
        );

        return JsonResponse::json($response, ['voice_template' => $template], 201);
    }

    /** @param array<string, string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $body = (array) ($request->getParsedBody() ?? []);

        $template = $this->templates->update(
            $user,
            (int) ($args['id'] ?? 0),
            (string) ($body['name'] ?? ''),
            (string) ($body['instruction'] ?? ''),
            (string) ($body['vocabulary'] ?? ''),
            RequestIp::hash($request),
        );

        return JsonResponse::json($response, ['voice_template' => $template]);
    }

    /** @param array<string, string> $args */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $this->templates->delete($user, (int) ($args['id'] ?? 0), RequestIp::hash($request));

        return $response->withStatus(204);
    }
}
