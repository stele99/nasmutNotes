<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\JsonResponse;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

final class JsonResponseTest extends TestCase
{
    public function testInvalidUtf8IsSubstitutedInsteadOfProducingAnEmptyBody(): void
    {
        $response = JsonResponse::json(new Response(), ['title' => "ungueltig\xFF"]);
        $body = (string) $response->getBody();

        self::assertNotSame('', $body);
        self::assertSame(['title' => 'ungueltig�'], json_decode($body, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }
}
