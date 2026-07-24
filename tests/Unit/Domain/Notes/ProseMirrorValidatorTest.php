<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Notes;

use App\Domain\Notes\NoteContentException;
use App\Domain\Notes\ProseMirrorValidator;
use PHPUnit\Framework\TestCase;

final class ProseMirrorValidatorTest extends TestCase
{
    private ProseMirrorValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ProseMirrorValidator();
    }

    public function testAcceptsAllowedNodesAndMarks(): void
    {
        $this->expectNotToPerformAssertions();

        $doc = [
            'type' => 'doc',
            'content' => [
                ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Titel']]],
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'text' => 'fett', 'marks' => [['type' => 'bold']]],
                    ['type' => 'text', 'text' => 'link', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]]],
                ]],
                ['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [['type' => 'text', 'text' => 'echo 1;']]],
                ['type' => 'taskList', 'content' => [
                    ['type' => 'taskItem', 'attrs' => ['checked' => true], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x']]]]],
                ]],
                ['type' => 'horizontalRule'],
            ],
        ];

        $this->validator->validate($doc);
    }

    public function testRejectsDisallowedNodeType(): void
    {
        $this->expectException(NoteContentException::class);

        $this->validator->validate([
            'type' => 'doc',
            'content' => [['type' => 'script', 'content' => [['type' => 'text', 'text' => 'alert(1)']]]],
        ]);
    }

    public function testRejectsHeadingLevelOutsideH1ToH3(): void
    {
        $this->expectException(NoteContentException::class);

        $this->validator->validate([
            'type' => 'doc',
            'content' => [['type' => 'heading', 'attrs' => ['level' => 4], 'content' => [['type' => 'text', 'text' => 'x']]]],
        ]);
    }

    public function testRejectsJavascriptLinkHref(): void
    {
        $this->expectException(NoteContentException::class);

        $this->validator->validate([
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'click', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]]],
            ]]],
        ]);
    }

    public function testRejectsDisallowedMarkType(): void
    {
        $this->expectException(NoteContentException::class);

        $this->validator->validate([
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'underline']]],
            ]]],
        ]);
    }

    public function testRejectsNonDocRoot(): void
    {
        $this->expectException(NoteContentException::class);

        $this->validator->validate(['type' => 'paragraph', 'content' => []]);
    }

    public function testExtractTextJoinsParagraphsWithNewlines(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Titel']]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Erster Absatz']]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Zweiter Absatz']]],
            ],
        ];

        $text = $this->validator->extractText($doc);

        self::assertStringContainsString('Titel', $text);
        self::assertStringContainsString('Erster Absatz', $text);
        self::assertStringContainsString('Zweiter Absatz', $text);
    }
}
