<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseInterface as Response;

final class JsonResponse
{
    public static function json(Response $response, mixed $data, int $status = 200): Response
    {
        $encoded = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_THROW_ON_ERROR,
        );
        $response->getBody()->write($encoded);

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /** @param array<int, array{line?: int, reason?: string, message: string}> $details */
    public static function error(Response $response, string $code, string $message, int $status = 400, array $details = []): Response
    {
        $payload = ['error' => ['code' => $code, 'message' => $message]];
        if ($details !== []) {
            $payload['error']['details'] = $details;
        }

        return self::json($response, $payload, $status);
    }
}
