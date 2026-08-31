<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Notes;

use App\Domain\Notes\ImageAnnotationValidator;
use App\Domain\Notes\NoteContentException;
use PHPUnit\Framework\TestCase;

final class ImageAnnotationValidatorTest extends TestCase
{
    private ImageAnnotationValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ImageAnnotationValidator();
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function line(array $overrides = []): array
    {
        return array_merge([
            'id' => 'abcd1234',
            't' => 'line',
            'c' => '#e11d48',
            'w' => 4,
            'x1' => 10,
            'y1' => 20,
            'x2' => 30,
            'y2' => 40,
            'head' => 'end',
        ], $overrides);
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

    public function testAcceptsMinimalObjectAndReturnsByteSize(): void
    {
        $bytes = $this->validator->validate($this->document([$this->line()]));

        self::assertGreaterThan(0, $bytes);
    }

    public function testAcceptsEveryItemTypeOnceValid(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validate($this->document([
            ['id' => 'pen00001', 't' => 'pen', 'c' => '#111827', 'w' => 6, 'p' => [[1, 2], [3, 4]]],
            $this->line(),
            ['id' => 'rect0001', 't' => 'rect', 'c' => '#2563eb', 'w' => 4, 'x' => 0, 'y' => 0, 'rw' => 100, 'rh' => 50, 'f' => null],
            ['id' => 'ellp0001', 't' => 'ellipse', 'c' => '#2563eb', 'w' => 4, 'x' => 0, 'y' => 0, 'rw' => 100, 'rh' => 50, 'f' => '#ffffff'],
            ['id' => 'text0001', 't' => 'text', 'c' => '#111827', 'x' => 5, 'y' => 6, 's' => 20, 'bw' => 100, 'bh' => 25, 'f' => null, 'text' => 'Hallo'],
            ['id' => 'rule0001', 't' => 'rules', 'c' => '#eab308', 'w' => 3, 'x' => 0, 'y' => 0, 'rw' => 400, 'rh' => 200, 'gap' => 32],
            ['id' => 'mark0001', 't' => 'marker', 'c' => '#2563eb', 'x' => 500, 'y' => 400, 'r' => 46, 'n' => 1],
            ['id' => 'mask0001', 't' => 'mask', 'c' => '#111827', 'x' => 10, 'y' => 10, 'rw' => 80, 'rh' => 20],
            $this->dim(),
        ]));
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

    public function testAcceptsMeasuringTapeWithoutLength(): void
    {
        $this->expectNotToPerformAssertions();

        $item = $this->dim();
        unset($item['text']);
        $this->validator->validate($this->document([$item]));
    }

    public function testRejectsEmptyMultilineOrOverlongLength(): void
    {
        foreach (['', '   ', "3,20\nm", str_repeat('x', 41)] as $label) {
            try {
                $this->validator->validate($this->document([$this->dim(['text' => $label])]));
                self::fail('Länge ' . json_encode($label) . ' wurde akzeptiert.');
            } catch (NoteContentException) {
                continue;
            }
        }

        $this->addToAssertionCount(1);
    }

    public function testRejectsMeasuringTapeWithoutLabelSize(): void
    {
        $this->expectException(NoteContentException::class);

        $this->validator->validate($this->document([$this->dim(['s' => 0])]));
    }

    public function testRejectsUnknownVersion(): void
    {
        $this->expectException(NoteContentException::class);

        $this->validator->validate(['v' => 2, 'space' => ['w' => 10, 'h' => 10], 'items' => []]);
    }

    public function testRejectsUnknownType(): void
    {
        $this->expectException(NoteContentException::class);

        $this->validator->validate($this->document([$this->line(['t' => 'iframe'])]));
    }

    public function testRejectsUnknownItemKey(): void
    {
        $this->expectException(NoteContentException::class);

        $this->validator->validate($this->document([$this->line(['onload' => 'alert(1)'])]));
    }

    public function testRejectsUnknownSpaceKey(): void
    {
        $this->expectException(NoteContentException::class);

        $this->validator->validate([
            'v' => 1,
            'space' => ['w' => 1000, 'h' => 800, 'depth' => 3],
            'items' => [],
        ]);
    }

    public function testRejectsNonHexColors(): void
    {
        foreach (['red', 'rgb(1,2,3)', 'url(#x)'] as $color) {
            try {
                $this->validator->validate($this->document([$this->line(['c' => $color])]));
                self::fail("Farbe {$color} wurde akzeptiert.");
            } catch (NoteContentException) {
                continue;
            }
        }

        $this->addToAssertionCount(1);
    }

    public function testRejectsNonFiniteAndOversizedCoordinates(): void
    {
        foreach ([INF, NAN, 1e9] as $value) {
            try {
                $this->validator->validate($this->document([$this->line(['x1' => $value])]));
                self::fail('Koordinate außerhalb des Bereichs wurde akzeptiert.');
            } catch (NoteContentException) {
                continue;
            }
        }

        $this->addToAssertionCount(1);
    }

    public function testRejectsZeroWidthAndNegativeSize(): void
    {
        try {
            $this->validator->validate($this->document([$this->line(['w' => 0])]));
            self::fail('Strichstärke 0 wurde akzeptiert.');
        } catch (NoteContentException) {
        }

        $this->expectException(NoteContentException::class);
        $this->validator->validate($this->document([
            ['id' => 'rect0001', 't' => 'rect', 'c' => '#2563eb', 'w' => 4, 'x' => 0, 'y' => 0, 'rw' => -5, 'rh' => 50, 'f' => null],
        ]));
    }

    public function testRejectsEmptyTooLongAndTooManyLineTexts(): void
    {
        try {
            $this->validator->validate($this->document([$this->textItem('   ')]));
            self::fail('Leerer Text wurde akzeptiert.');
        } catch (NoteContentException) {
        }
        $this->addToAssertionCount(1);

        try {
            $this->validator->validate($this->document([$this->textItem(str_repeat('x', 501))]));
            self::fail('Zu langer Text wurde akzeptiert.');
        } catch (NoteContentException) {
        }
        $this->addToAssertionCount(1);

        $this->expectException(NoteContentException::class);
        $this->validator->validate($this->document([
            $this->textItem(implode("\n", array_fill(0, 13, 'Zeile'))),
        ]));
    }

    public function testRejectsTooManyPoints(): void
    {
        $points = [];
        for ($index = 0; $index < 401; ++$index) {
            $points[] = [$index, $index];
        }

        $this->expectException(NoteContentException::class);
        $this->validator->validate($this->document([
            ['id' => 'pen00001', 't' => 'pen', 'c' => '#111827', 'w' => 6, 'p' => $points],
        ]));
    }

    public function testRejectsTooManyItems(): void
    {
        $items = [];
        for ($index = 0; $index < 201; ++$index) {
            $items[] = $this->line(['id' => sprintf('item%04d', $index)]);
        }

        $this->expectException(NoteContentException::class);
        $this->validator->validate($this->document($items));
    }

    public function testRejectsDuplicateIds(): void
    {
        $this->expectException(NoteContentException::class);

        $this->validator->validate($this->document([$this->line(), $this->line()]));
    }

    public function testRejectsObjectBeyondByteBudget(): void
    {
        $points = [];
        for ($index = 0; $index < 400; ++$index) {
            $points[] = [100.5 + $index, 200.5 + $index];
        }
        $items = [];
        for ($index = 0; $index < 8; ++$index) {
            $items[] = ['id' => sprintf('pen%05d', $index), 't' => 'pen', 'c' => '#111827', 'w' => 6, 'p' => $points];
        }
        $bytes = strlen((string) json_encode($this->document($items)));
        self::assertGreaterThan(ImageAnnotationValidator::MAX_BYTES_PER_IMAGE, $bytes);

        $this->expectException(NoteContentException::class);
        $this->validator->validate($this->document($items));
    }

    public function testTextsReturnsOnlyTextItemsInOrder(): void
    {
        $texts = ImageAnnotationValidator::texts($this->document([
            $this->line(),
            ['id' => 'text0001', 't' => 'text', 'c' => '#111827', 'x' => 5, 'y' => 6, 's' => 20, 'bw' => 100, 'bh' => 25, 'f' => null, 'text' => 'Erster'],
            ['id' => 'mark0001', 't' => 'marker', 'c' => '#2563eb', 'x' => 1, 'y' => 2, 'r' => 40, 'n' => 2],
            ['id' => 'text0002', 't' => 'text', 'c' => '#111827', 'x' => 5, 'y' => 60, 's' => 20, 'bw' => 100, 'bh' => 25, 'f' => null, 'text' => 'Zweiter'],
        ]));

        self::assertSame(['Erster', 'Zweiter'], $texts);
        self::assertSame([], ImageAnnotationValidator::texts(null));
        self::assertSame(['3,20 m'], ImageAnnotationValidator::texts($this->document([$this->dim()])));
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
}
