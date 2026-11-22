<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\RequestIp;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class RequestIpTest extends TestCase
{
    public function testPrefersCloudflareHeader(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/', ['REMOTE_ADDR' => '172.18.0.1'])
            ->withHeader('CF-Connecting-IP', '203.0.113.7')
            ->withHeader('X-Forwarded-For', '198.51.100.1, 172.18.0.1');

        self::assertSame('203.0.113.7', RequestIp::resolve($request));
    }

    public function testFallsBackToFirstForwardedForEntryThenRemoteAddr(): void
    {
        $factory = new ServerRequestFactory();
        $forwarded = $factory->createServerRequest('GET', '/', ['REMOTE_ADDR' => '172.18.0.1'])
            ->withHeader('X-Forwarded-For', '2001:db8::1, 172.18.0.1');
        $direct = $factory->createServerRequest('GET', '/', ['REMOTE_ADDR' => '127.0.0.1']);

        self::assertSame('2001:db8::1', RequestIp::resolve($forwarded));
        self::assertSame('127.0.0.1', RequestIp::resolve($direct));
    }

    public function testIgnoresMalformedHeaderValues(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/', ['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('CF-Connecting-IP', 'not-an-ip')
            ->withHeader('X-Forwarded-For', '<script>');

        self::assertSame('127.0.0.1', RequestIp::resolve($request));
        self::assertSame(64, strlen(RequestIp::hash($request)));
    }
}
