<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import;

use App\Domain\Import\MarkdownConverter;
use App\Domain\Notes\ProseMirrorValidator;
use PHPUnit\Framework\TestCase;

final class MarkdownConverterTest extends TestCase
{
    private MarkdownConverter $converter;
    private ProseMirrorValidator $validator;

    protected function setUp(): void
    {
        $this->converter = new MarkdownConverter();
        $this->validator = new ProseMirrorValidator();
    }

    public function testSplitsFrontMatterFromBody(): void
    {
        $split = $this->converter->splitFrontMatter(
            "---\ndate: 2025-10-03 23:04:57\ncreated: 2025-10-03 23:04:27\ncategories:\n- Rezepte\n---\n\n## Titel\n"
        );

        self::assertSame('2025-10-03 23:04:57', $split['meta']['date']);
        self::assertSame('2025-10-03 23:04:27', $split['meta']['created']);
        self::assertSame("\n## Titel\n", $split['body']);
    }

    public function testBodyWithoutFrontMatterStaysUntouched(): void
    {
        $split = $this->converter->splitFrontMatter("# Titel\n\nText");

        self::assertSame([], $split['meta']);
        self::assertSame("# Titel\n\nText", $split['body']);
    }

    public function testHeadingsBeyondLevelThreeAreClamped(): void
    {
        $doc = $this->converter->toDocument("# Eins\n\n#### Vier\n");

        self::assertSame(1, $doc['content'][0]['attrs']['level']);
        self::assertSame(3, $doc['content'][1]['attrs']['level']);
        $this->validator->validate($doc);
    }

    public function testInlineMarksAndEscapes(): void
    {
        $doc = $this->converter->toDocument('Das ist **fett**, *kursiv*, ~~weg~~ und `Code`. Nicht \*kursiv\*.');
        $paragraph = $doc['content'][0]['content'];

        self::assertSame('fett', $paragraph[1]['text']);
        self::assertSame('bold', $paragraph[1]['marks'][0]['type']);
        self::assertSame('kursiv', $paragraph[3]['text']);
        self::assertSame('italic', $paragraph[3]['marks'][0]['type']);
        self::assertSame('weg', $paragraph[5]['text']);
        self::assertSame('strike', $paragraph[5]['marks'][0]['type']);
        self::assertSame('Code', $paragraph[7]['text']);
        self::assertSame('code', $paragraph[7]['marks'][0]['type']);
        self::assertStringContainsString('Nicht *kursiv*.', $paragraph[8]['text']);
        $this->validator->validate($doc);
    }

    public function testLinksKeepHttpUrlsAndDropOtherSchemes(): void
    {
        $doc = $this->converter->toDocument(
            '[Seite](https://example.com/a) und [Mail](mailto:a@example.com)'
        );
        $paragraph = $doc['content'][0]['content'];

        self::assertSame('Seite', $paragraph[0]['text']);
        self::assertSame('link', $paragraph[0]['marks'][0]['type']);
        self::assertSame('https://example.com/a', $paragraph[0]['marks'][0]['attrs']['href']);
        // mailto: würde die Validierung ablehnen - es bleibt der Beschriftungstext.
        self::assertSame('Mail', $paragraph[2]['text']);
        self::assertArrayNotHasKey('marks', $paragraph[2]);
        $this->validator->validate($doc);
    }

    public function testTaskListWithCheckedState(): void
    {
        $doc = $this->converter->toDocument("- [x] Erledigt\n- [ ] Offen\n");

        self::assertSame('taskList', $doc['content'][0]['type']);
        self::assertTrue($doc['content'][0]['content'][0]['attrs']['checked']);
        self::assertFalse($doc['content'][0]['content'][1]['attrs']['checked']);
        self::assertSame('Erledigt', $doc['content'][0]['content'][0]['content'][0]['content'][0]['text']);
        $this->validator->validate($doc);
    }

    public function testNestedBulletList(): void
    {
        $doc = $this->converter->toDocument("- Eins\n  - Unterpunkt\n- Zwei\n");
        $list = $doc['content'][0];

        self::assertSame('bulletList', $list['type']);
        self::assertCount(2, $list['content']);
        self::assertSame('bulletList', $list['content'][0]['content'][1]['type']);
        $this->validator->validate($doc);
    }

    public function testOrderedListAndCodeBlockWithLanguage(): void
    {
        $doc = $this->converter->toDocument("1. Erstens\n2. Zweitens\n\n```php\n<?php echo 1;\n```\n");

        self::assertSame('orderedList', $doc['content'][0]['type']);
        self::assertCount(2, $doc['content'][0]['content']);
        self::assertSame('codeBlock', $doc['content'][1]['type']);
        self::assertSame('php', $doc['content'][1]['attrs']['language']);
        self::assertSame('<?php echo 1;', $doc['content'][1]['content'][0]['text']);
        $this->validator->validate($doc);
    }

    public function testTableBecomesHeaderAndCells(): void
    {
        $doc = $this->converter->toDocument("| A | B |\n| --- | --- |\n| 1 | 2 |\n");
        $table = $doc['content'][0];

        self::assertSame('table', $table['type']);
        self::assertSame('tableHeader', $table['content'][0]['content'][0]['type']);
        self::assertSame('A', $table['content'][0]['content'][0]['content'][0]['content'][0]['text']);
        self::assertSame('tableCell', $table['content'][1]['content'][0]['type']);
        self::assertSame('2', $table['content'][1]['content'][1]['content'][0]['content'][0]['text']);
        $this->validator->validate($doc);
    }

    public function testEmptyTableCellsCarryNoLineBreak(): void
    {
        $doc = $this->converter->toDocument("| A | B |\n| --- | --- |\n| <br> | Text |\n");
        $cell = $doc['content'][0]['content'][1]['content'][0];

        self::assertSame(['type' => 'paragraph'], $cell['content'][0]);
    }

    public function testQuoteAndHorizontalRule(): void
    {
        $doc = $this->converter->toDocument("> Zitat\n\n---\n\nText");

        self::assertSame('blockquote', $doc['content'][0]['type']);
        self::assertSame('Zitat', $doc['content'][0]['content'][0]['content'][0]['text']);
        self::assertSame('horizontalRule', $doc['content'][1]['type']);
        $this->validator->validate($doc);
    }

    public function testHtmlBreaksBecomeHardBreaksAndOtherTagsVanish(): void
    {
        $doc = $this->converter->toDocument('Eins<br>Zwei<span>Drei</span>');
        $paragraph = $doc['content'][0]['content'];

        self::assertSame('Eins', $paragraph[0]['text']);
        self::assertSame('hardBreak', $paragraph[1]['type']);
        self::assertSame('ZweiDrei', $paragraph[2]['text']);
        $this->validator->validate($doc);
    }

    /** Bilder sind Blockknoten; ein Absatz muss an ihrer Stelle geteilt werden. */
    public function testImageIsHoistedOutOfParagraph(): void
    {
        $src = '/api/attachments/' . str_repeat('a', 64);
        $doc = $this->converter->toDocument(
            'Davor ![Alt](Files/bild.png) dahinter',
            static fn (string $target, string $label, bool $asImage): array
                => ['kind' => 'image', 'src' => $src, 'width' => 320, 'height' => 200],
        );

        self::assertSame('paragraph', $doc['content'][0]['type']);
        self::assertSame('image', $doc['content'][1]['type']);
        self::assertSame($src, $doc['content'][1]['attrs']['src']);
        self::assertSame('Alt', $doc['content'][1]['attrs']['alt']);
        self::assertSame(320, $doc['content'][1]['attrs']['width']);
        self::assertSame('paragraph', $doc['content'][2]['type']);
        $this->validator->validate($doc);
    }

    public function testUnresolvedAssetKeepsItsLabel(): void
    {
        $doc = $this->converter->toDocument(
            '[Handbuch.pdf](Files/Handbuch.pdf)',
            static fn (): ?array => null,
        );

        self::assertSame('Handbuch.pdf', $doc['content'][0]['content'][0]['text']);
        $this->validator->validate($doc);
    }

    public function testResolverMayReplaceAssetWithText(): void
    {
        $doc = $this->converter->toDocument(
            '[x](Files/Handbuch.pdf)',
            static fn (): array => ['kind' => 'text', 'text' => 'Anhang: Handbuch.pdf'],
        );

        self::assertSame('Anhang: Handbuch.pdf', $doc['content'][0]['content'][0]['text']);
    }

    public function testDeadResourceLinksAreCountedAndRemoved(): void
    {
        $body = 'Text !\[\[./_resources/bild.png\]\] Ende';

        self::assertSame(1, $this->converter->countDeadResourceLinks($body));

        $doc = $this->converter->toDocument($body);
        self::assertSame('Text  Ende', $doc['content'][0]['content'][0]['text']);
    }

    public function testFirstHeadingIsReadable(): void
    {
        self::assertSame('Mein **Titel**', $this->converter->firstHeading("## Mein \\*\\*Titel\\*\\*\n\nText"));
        self::assertNull($this->converter->firstHeading('Nur Text'));
    }

    public function testUnclosedCodeFenceDoesNotSwallowTheDocument(): void
    {
        $doc = $this->converter->toDocument("```\ncode\n");

        self::assertSame('codeBlock', $doc['content'][0]['type']);
        self::assertSame('code', $doc['content'][0]['content'][0]['text']);
        $this->validator->validate($doc);
    }
}
