<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Export;

use App\Domain\Export\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

final class MarkdownRendererTest extends TestCase
{
    private MarkdownRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new MarkdownRenderer();
    }

    public function testRendersHeadingsAndParagraphs(): void
    {
        $markdown = $this->render([
            $this->heading(1, 'Titel'),
            $this->paragraph([$this->text('Ein Absatz.')]),
            $this->heading(3, 'Klein'),
        ]);

        self::assertSame("# Titel\n\nEin Absatz.\n\n### Klein\n", $markdown);
    }

    public function testRendersInlineMarks(): void
    {
        $markdown = $this->render([
            $this->paragraph([
                $this->text('fett', [['type' => 'bold']]),
                $this->text(' '),
                $this->text('kursiv', [['type' => 'italic']]),
                $this->text(' '),
                $this->text('weg', [['type' => 'strike']]),
                $this->text(' '),
                $this->text('code()', [['type' => 'code']]),
            ]),
        ]);

        self::assertSame("**fett** *kursiv* ~~weg~~ `code()`\n", $markdown);
    }

    /** Unterstrichen kennt Markdown nicht - Inline-HTML ist die ehrlichste Näherung. */
    public function testUnderlineFallsBackToHtml(): void
    {
        $markdown = $this->render([
            $this->paragraph([$this->text('wichtig', [['type' => 'underline']])]),
        ]);

        self::assertSame("<u>wichtig</u>\n", $markdown);
    }

    public function testRendersLinks(): void
    {
        $markdown = $this->render([
            $this->paragraph([
                $this->text('Seite', [['type' => 'link', 'attrs' => ['href' => 'https://example.com/a']]]),
            ]),
        ]);

        self::assertSame("[Seite](https://example.com/a)\n", $markdown);
    }

    /** Ein Ziel mit Leerzeichen zerfiele ohne spitze Klammern in zwei Teile. */
    public function testWrapsUrlsWithSpaces(): void
    {
        $markdown = $this->render([
            $this->paragraph([
                $this->text('Datei', [['type' => 'link', 'attrs' => ['href' => 'files/mein bild.png']]]),
            ]),
        ]);

        self::assertSame("[Datei](<files/mein bild.png>)\n", $markdown);
    }

    public function testMarksNeverEncloseSurroundingSpace(): void
    {
        $markdown = $this->render([
            $this->paragraph([$this->text('fett ', [['type' => 'bold']]), $this->text('normal')]),
        ]);

        // "** fett **" bliebe bei jedem Markdown-Leser wörtlich stehen.
        self::assertSame("**fett** normal\n", $markdown);
    }

    public function testRendersBulletAndOrderedLists(): void
    {
        $markdown = $this->render([
            ['type' => 'bulletList', 'content' => [
                $this->listItem('eins'),
                $this->listItem('zwei'),
            ]],
            ['type' => 'orderedList', 'attrs' => ['start' => 3], 'content' => [
                $this->listItem('drei'),
                $this->listItem('vier'),
            ]],
        ]);

        self::assertSame("- eins\n- zwei\n\n3. drei\n4. vier\n", $markdown);
    }

    public function testRendersNestedLists(): void
    {
        $markdown = $this->render([
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [
                    $this->paragraph([$this->text('oben')]),
                    ['type' => 'bulletList', 'content' => [$this->listItem('unten')]],
                ]],
            ]],
        ]);

        self::assertSame("- oben\n  - unten\n", $markdown);
    }

    public function testRendersTaskList(): void
    {
        $markdown = $this->render([
            ['type' => 'taskList', 'content' => [
                ['type' => 'taskItem', 'attrs' => ['checked' => true], 'content' => [
                    $this->paragraph([$this->text('erledigt')]),
                ]],
                ['type' => 'taskItem', 'attrs' => ['checked' => false], 'content' => [
                    $this->paragraph([$this->text('offen')]),
                ]],
            ]],
        ]);

        self::assertSame("- [x] erledigt\n- [ ] offen\n", $markdown);
    }

    public function testRendersCodeBlockWithLanguage(): void
    {
        $markdown = $this->render([
            ['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [
                $this->text("echo 'a';"),
            ]],
        ]);

        self::assertSame("```php\necho 'a';\n```\n", $markdown);
    }

    /** Enthält der Code selbst einen Zaun, muss der äußere länger sein. */
    public function testCodeBlockFenceGrowsPastInnerBackticks(): void
    {
        $markdown = $this->render([
            ['type' => 'codeBlock', 'content' => [$this->text("```\nverschachtelt\n```")]],
        ]);

        self::assertStringStartsWith("````\n", $markdown);
        self::assertStringEndsWith("\n````\n", $markdown);
    }

    public function testRendersBlockquote(): void
    {
        $markdown = $this->render([
            ['type' => 'blockquote', 'content' => [
                $this->paragraph([$this->text('zitiert')]),
                $this->paragraph([$this->text('zweiter Absatz')]),
            ]],
        ]);

        self::assertSame("> zitiert\n>\n> zweiter Absatz\n", $markdown);
    }

    public function testRendersTableWithSeparatorRow(): void
    {
        $markdown = $this->render([
            ['type' => 'table', 'content' => [
                ['type' => 'tableRow', 'content' => [
                    $this->cell('tableHeader', 'A'),
                    $this->cell('tableHeader', 'B'),
                ]],
                ['type' => 'tableRow', 'content' => [
                    $this->cell('tableCell', 'eins'),
                    $this->cell('tableCell', 'zwei'),
                ]],
            ]],
        ]);

        self::assertSame("| A | B |\n| --- | --- |\n| eins | zwei |\n", $markdown);
    }

    public function testEscapesPipesInsideTableCells(): void
    {
        $markdown = $this->render([
            ['type' => 'table', 'content' => [
                ['type' => 'tableRow', 'content' => [$this->cell('tableHeader', 'a|b')]],
            ]],
        ]);

        self::assertStringContainsString('| a\\|b |', $markdown);
    }

    public function testRendersHardBreakAndHorizontalRule(): void
    {
        $markdown = $this->render([
            $this->paragraph([
                $this->text('oben'),
                ['type' => 'hardBreak'],
                $this->text('unten'),
            ]),
            ['type' => 'horizontalRule'],
        ]);

        self::assertSame("oben  \nunten\n\n---\n", $markdown);
    }

    public function testEscapesMarkdownSyntaxInPlainText(): void
    {
        $markdown = $this->render([
            $this->paragraph([$this->text('5 * 3 und [Klammer] und `tick`')]),
        ]);

        self::assertSame("5 \\* 3 und \\[Klammer\\] und \\`tick\\`\n", $markdown);
    }

    /** `snake_case` soll lesbar bleiben - Unterstriche nur an Wortgrenzen. */
    public function testDoesNotEscapeUnderscoresInsideWords(): void
    {
        $markdown = $this->render([
            $this->paragraph([$this->text('mein_datei_name und _betont_')]),
        ]);

        self::assertSame("mein_datei_name und \\_betont\\_\n", $markdown);
    }

    public function testEscapesLineStartConstructs(): void
    {
        $markdown = $this->render([
            $this->paragraph([$this->text('- kein Listeneintrag')]),
            $this->paragraph([$this->text('# keine Überschrift')]),
        ]);

        self::assertSame("\\- kein Listeneintrag\n\n\\# keine Überschrift\n", $markdown);
    }

    public function testResolvesImagesThroughCallback(): void
    {
        $token = str_repeat('a', 64);
        $markdown = $this->render(
            [['type' => 'image', 'attrs' => ['src' => "/api/attachments/{$token}", 'alt' => 'Ein Bild']]],
            static fn (string $given): ?string => $given === $token ? 'files/bild.png' : null,
        );

        self::assertSame("![Ein Bild](files/bild.png)\n", $markdown);
    }

    /** Fehlt die Datei im Archiv, wäre ein Link darauf nur ein toter Verweis. */
    public function testSkipsImagesThatCannotBeResolved(): void
    {
        $markdown = $this->render(
            [
                ['type' => 'image', 'attrs' => ['src' => '/api/attachments/' . str_repeat('b', 64)]],
                $this->paragraph([$this->text('danach')]),
            ],
            static fn (string $token): ?string => null,
        );

        self::assertSame("danach\n", $markdown);
    }

    public function testKeepsExternalImageUrls(): void
    {
        $markdown = $this->render(
            [['type' => 'image', 'attrs' => ['src' => 'https://example.com/b.png', 'alt' => '']]],
            static fn (string $token): ?string => null,
        );

        self::assertSame("![](https://example.com/b.png)\n", $markdown);
    }

    public function testIgnoresUnknownNodeTypes(): void
    {
        $markdown = $this->render([
            ['type' => 'somethingElse', 'content' => [$this->text('weg')]],
            $this->paragraph([$this->text('bleibt')]),
        ]);

        self::assertSame("bleibt\n", $markdown);
    }

    // ---- Hilfsfunktionen -------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $content
     */
    private function render(array $content, ?callable $resolveImage = null): string
    {
        return $this->renderer->render(['type' => 'doc', 'content' => $content], $resolveImage);
    }

    /**
     * @param array<int, array<string, mixed>> $content
     *
     * @return array<string, mixed>
     */
    private function paragraph(array $content): array
    {
        return ['type' => 'paragraph', 'content' => $content];
    }

    /** @return array<string, mixed> */
    private function heading(int $level, string $text): array
    {
        return ['type' => 'heading', 'attrs' => ['level' => $level], 'content' => [$this->text($text)]];
    }

    /**
     * @param array<int, array<string, mixed>> $marks
     *
     * @return array<string, mixed>
     */
    private function text(string $text, array $marks = []): array
    {
        $node = ['type' => 'text', 'text' => $text];
        if ($marks !== []) {
            $node['marks'] = $marks;
        }

        return $node;
    }

    /** @return array<string, mixed> */
    private function listItem(string $text): array
    {
        return ['type' => 'listItem', 'content' => [$this->paragraph([$this->text($text)])]];
    }

    /** @return array<string, mixed> */
    private function cell(string $type, string $text): array
    {
        return ['type' => $type, 'content' => [$this->paragraph([$this->text($text)])]];
    }
}
