<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Geo;

use App\Domain\Geo\ForwardGeocoder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;

final class ForwardGeocoderTest extends TestCase
{
    public function testReturnsValidSearchResultsAndSendsTheQuery(): void
    {
        $requests = [];
        $http = new MockHandler([new Response(200, [], (string) json_encode([
            ['lat' => '48.137', 'lon' => '11.575', 'display_name' => 'Marienplatz, München'],
            ['lat' => 'ungueltig', 'lon' => '11.5', 'display_name' => 'Kaputt'],
        ]))]);
        $stack = HandlerStack::create($http);
        $stack->push(Middleware::history($requests));
        $geocoder = new ForwardGeocoder(
            new NullLogger(),
            ForwardGeocoder::DEFAULT_URL,
            'https://notes.example.com',
            'de',
            new Client(['handler' => $stack, 'http_errors' => false]),
        );

        self::assertSame([
            ['lat' => 48.137, 'lon' => 11.575, 'label' => 'Marienplatz, München'],
        ], $geocoder->search('Marienplatz München'));

        self::assertNotEmpty($requests);
        $request = $requests[0]['request'];
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertStringContainsString('q=Marienplatz%20M%C3%BCnchen', $request->getUri()->getQuery());
        self::assertStringContainsString('notes.example.com', $request->getHeaderLine('User-Agent'));
    }

    public function testFailuresAndDisabledSearchReturnNoResults(): void
    {
        $http = new MockHandler([new Response(503)]);
        $geocoder = new ForwardGeocoder(
            new NullLogger(),
            ForwardGeocoder::DEFAULT_URL,
            '',
            'de',
            new Client(['handler' => HandlerStack::create($http), 'http_errors' => false]),
        );

        self::assertSame([], $geocoder->search('Stuttgart'));
        self::assertSame([], (new ForwardGeocoder(new NullLogger(), ''))->search('Stuttgart'));
    }
}
