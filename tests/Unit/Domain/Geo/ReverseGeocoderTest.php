<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Geo;

use App\Domain\Geo\ReverseGeocoder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;

final class ReverseGeocoderTest extends TestCase
{
    private MockHandler $http;
    private ?RequestInterface $lastRequest = null;

    protected function setUp(): void
    {
        $this->http = new MockHandler();
        $this->lastRequest = null;
    }

    public function testBuildsShortAddressFromTheDetailedAnswer(): void
    {
        $this->queue([
            'display_name' => 'Sehr, lange, vollständige, Anschrift',
            'address' => [
                'road' => 'Konrad-Adenauer-Straße',
                'house_number' => '30',
                'postcode' => '70173',
                'city' => 'Stuttgart',
                'country' => 'Deutschland',
                'state' => 'Baden-Württemberg',
            ],
        ]);

        self::assertSame(
            'Konrad-Adenauer-Straße 30, 70173 Stuttgart, Deutschland',
            $this->geocoder()->lookup(48.775846, 9.182932),
        );
    }

    public function testFallsBackToTheFullNameWhenNoPartsAreUsable(): void
    {
        $this->queue(['display_name' => 'Irgendwo im Nirgendwo', 'address' => []]);

        self::assertSame('Irgendwo im Nirgendwo', $this->geocoder()->lookup(0.0, 0.0));
    }

    public function testSendsTheCoordinatesAndAnIdentifyingUserAgent(): void
    {
        $this->queue(['address' => ['city' => 'Stuttgart']]);

        $this->geocoder()->lookup(48.775846, 9.182932);

        $request = $this->lastRequest;
        self::assertNotNull($request);
        self::assertStringContainsString('lat=48.775846', $request->getUri()->getQuery());
        self::assertStringContainsString('lon=9.182932', $request->getUri()->getQuery());
        // Nominatim weist Anfragen ohne aussagekräftige Kennung ab.
        self::assertStringContainsString('nasmutNotes', $request->getHeaderLine('User-Agent'));
        self::assertStringContainsString('https://notes.example.com', $request->getHeaderLine('User-Agent'));
    }

    public function testAFailingLookupStaysSilentInsteadOfThrowing(): void
    {
        $this->http->append(new Response(503, [], 'kaputt'));
        self::assertNull($this->geocoder()->lookup(48.0, 9.0));

        $this->http->append(new \RuntimeException('Netz weg'));
        self::assertNull($this->geocoder()->lookup(48.0, 9.0));
    }

    public function testAnEmptyEndpointSwitchesTheLookupOff(): void
    {
        $geocoder = new ReverseGeocoder(new NullLogger(), '', 'https://notes.example.com');

        self::assertFalse($geocoder->isEnabled());
        self::assertNull($geocoder->lookup(48.0, 9.0));
        self::assertNull($this->lastRequest);
    }

    /** @param array<string, mixed> $payload */
    private function queue(array $payload): void
    {
        $this->http->append(new Response(200, [
            'Content-Type' => 'application/json',
        ], (string) json_encode($payload)));
    }

    private function geocoder(): ReverseGeocoder
    {
        $stack = HandlerStack::create($this->http);
        $stack->push(Middleware::mapRequest(function (RequestInterface $request): RequestInterface {
            $this->lastRequest = $request;

            return $request;
        }));

        return new ReverseGeocoder(
            new NullLogger(),
            ReverseGeocoder::DEFAULT_URL,
            'https://notes.example.com',
            'de',
            new Client(['handler' => $stack, 'http_errors' => false]),
        );
    }
}
