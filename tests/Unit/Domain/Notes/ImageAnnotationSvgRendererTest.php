<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Notes;

use App\Domain\Notes\ImageAnnotationSvgRenderer;
use PHPUnit\Framework\TestCase;

final class ImageAnnotationSvgRendererTest extends TestCase
{
    private ImageAnnotationSvgRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new ImageAnnotationSvgRenderer();
    }

    /**
     * @param array<int, array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private function document(array $items): array
    {
        return ['v' => 1, 'space' => ['w' => 1000, 'h' => 800], 'items' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    private function textItem(string $text): array
    {
        return [
            'id' => 'text0001',
            't' => 'text',
            'c' => '#111827',
            'x' => 5,
            'y' => 6,
            's' => 20,
            'bw' => 100,
            'bh' => 25,
            'f' => null,
            'text' => $text,
        ];
    }

    public function testEscapesTextInsteadOfEmittingMarkup(): void
    {
        $svg = $this->renderer->render($this->document([
            $this->textItem('<script>alert("x")</script> & mehr'),
        ]));

        self::assertStringNotContainsString('<script>', $svg);
        self::assertStringContainsString('&lt;script&gt;', $svg);
        self::assertStringContainsString('&quot;', $svg);
        self::assertStringContainsString('&amp;', $svg);
    }

    public function testFallsBackToBlackForNonHexColors(): void
    {
        $svg = $this->renderer->render($this->document([
            ['id' => 'mask0001', 't' => 'mask', 'c' => 'url(#x)', 'x' => 0, 'y' => 0, 'rw' => 10, 'rh' => 10],
        ]));

        self::assertStringContainsString('fill="#000000"', $svg);
    }

    public function testUnknownTypeRendersNothingForThatItem(): void
    {
        $svg = $this->renderer->render($this->document([
            ['id' => 'abcd1234', 't' => 'iframe', 'c' => '#111827'],
            ['id' => 'mask0001', 't' => 'mask', 'c' => '#111827', 'x' => 0, 'y' => 0, 'rw' => 10, 'rh' => 10],
        ]));

        self::assertStringNotContainsString('<iframe', $svg);
        self::assertSame(1, substr_count($svg, '<rect'));
    }

    public function testEmptyItemListReturnsEmptyString(): void
    {
        self::assertSame('', $this->renderer->render($this->document([])));
        self::assertSame('', $this->renderer->render(null));
    }

    public function testRulesWithZeroGapReturnEmptyString(): void
    {
        $svg = $this->renderer->render($this->document([
            ['id' => 'rule0001', 't' => 'rules', 'c' => '#eab308', 'w' => 3, 'x' => 0, 'y' => 0, 'rw' => 400, 'rh' => 200, 'gap' => 0],
        ]));

        self::assertSame('', $svg);
    }

    public function testRulesAreCappedAtTwoHundredLines(): void
    {
        $svg = $this->renderer->render($this->document([
            ['id' => 'rule0001', 't' => 'rules', 'c' => '#eab308', 'w' => 3, 'x' => 0, 'y' => 0, 'rw' => 400, 'rh' => 100000, 'gap' => 0.5],
        ]));

        self::assertSame(200, substr_count($svg, '<path'));
    }

    public function testLineWithBothHeadsRendersThreePaths(): void
    {
        $svg = $this->renderer->render($this->document([
            ['id' => 'abcd1234', 't' => 'line', 'c' => '#e11d48', 'w' => 4, 'x1' => 10, 'y1' => 20, 'x2' => 30, 'y2' => 40, 'head' => 'both'],
        ]));

        self::assertSame(3, substr_count($svg, '<path'));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function dim(array $overrides = []): array
    {
        return array_merge([
            'id' => 'dim00001',
            't' => 'dim',
            'c' => '#e11d48',
            'w' => 6,
            'x1' => 100,
            'y1' => 400,
            'x2' => 700,
            'y2' => 400,
            's' => 40,
            'bw' => 120,
            'bh' => 50,
            'f' => '#ffffff',
            'text' => '3,20 m',
        ], $overrides);
    }

    public function testMeasuringTapeDrawsLineTicksAndRotatedLabel(): void
    {
        $svg = $this->renderer->render($this->document([$this->dim()]));

        self::assertSame(3, substr_count($svg, '<path'));
        self::assertStringContainsString('<g transform="translate(400 400) rotate(0)">', $svg);
        self::assertStringContainsString('>3,20 m</text>', $svg);
        self::assertStringContainsString('text-anchor="middle"', $svg);
    }

    public function testMeasuringTapeWithoutLengthDrawsOnlyTheLine(): void
    {
        $item = $this->dim();
        unset($item['text']);
        $svg = $this->renderer->render($this->document([$item]));

        self::assertSame(3, substr_count($svg, '<path'));
        self::assertStringNotContainsString('<g transform', $svg);
        self::assertStringNotContainsString('<text', $svg);
    }

    /**
     * Die Länge steht nie auf dem Kopf: Der Drehwinkel wird auf ±90°
     * zurückgeklappt - dieselbe Rechnung wie dimLabelAngle() in render.js.
     */
    public function testMeasuringTapeLabelNeverReadsUpsideDown(): void
    {
        $svg = $this->renderer->render($this->document([
            $this->dim(['x1' => 700, 'y1' => 400, 'x2' => 100, 'y2' => 400]),
        ]));
        self::assertStringContainsString('rotate(0)', $svg);

        $svg = $this->renderer->render($this->document([
            $this->dim(['x1' => 100, 'y1' => 0, 'x2' => 0, 'y2' => 100]),
        ]));
        self::assertStringContainsString('rotate(-45)', $svg);
    }

    /**
     * Math.round() in render.js rundet halbe Werte nach oben, auch negative -
     * die gedrehte Beschriftung rechnet laufend in negativen Koordinaten.
     */
    public function testRoundsHalvesUpwardsLikeTheJavaScriptTwin(): void
    {
        $svg = $this->renderer->render($this->document([
            $this->dim(['bw' => 120.5, 's' => 40]),
        ]));

        self::assertStringContainsString('x="-70.2"', $svg);
        self::assertStringNotContainsString('x="-70.3"', $svg);
    }

    public function testViewBoxMatchesSpace(): void
    {
        $svg = $this->renderer->render($this->document([
            ['id' => 'mask0001', 't' => 'mask', 'c' => '#111827', 'x' => 0, 'y' => 0, 'rw' => 10, 'rh' => 10],
        ]));

        self::assertStringContainsString('<svg viewBox="0 0 1000 800"', $svg);
    }
}
