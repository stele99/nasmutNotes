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
                    ['type' => 'text', 'text' => 'unterstrichen', 'marks' => [['type' => 'underline']]],
                    ['type' => 'text', 'text' => 'link', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]]],
                ]],
                ['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [['type' => 'text', 'text' => 'echo 1;']]],
                ['type' => 'taskList', 'content' => [
                    ['type' => 'taskItem', 'attrs' => ['checked' => true], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x']]]]],
                ]],
                ['type' => 'horizontalRule'],
                [
                    'type' => 'table',
                    'content' => [
                        [
                            'type' => 'tableRow',
                            'content' => [
                                [
                                    'type' => 'tableHeader',
                                    'attrs' => ['colspan' => 1, 'rowspan' => 1],
                                    'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'A']]]],
                                ],
                                [
                                    'type' => 'tableHeader',
                                    'attrs' => ['colspan' => 1, 'rowspan' => 1],
                                    'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'B']]]],
                                ],
                            ],
                        ],
                        [
                            'type' => 'tableRow',
                            'content' => [
                                [
                                    'type' => 'tableCell',
                                    'attrs' => ['colspan' => 1, 'rowspan' => 1],
                                    'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '1']]]],
                                ],
                                [
                                    'type' => 'tableCell',
                                    'attrs' => ['colspan' => 1, 'rowspan' => 1],
                                    'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '2']]]],
                                ],
                            ],
                        ],
                    ],
                ],
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

    public function testAcceptsProtectedImageNode(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator->validate([
            'type' => 'doc',
            'content' => [[
                'type' => 'image',
                'attrs' => [
                    'src' => '/api/attachments/' . str_repeat('a', 64),
                    'alt' => 'Screenshot',
                    'title' => null,
                    'width' => 800,
                    'height' => 600,
                ],
            ]],
        ]);
    }

    public function testRejectsExternalImageSource(): void
    {
        $this->expectException(NoteContentException::class);

        $this->validator->validate([
            'type' => 'doc',
            'content' => [[
                'type' => 'image',
                'attrs' => ['src' => 'https://example.com/image.png'],
            ]],
        ]);
    }

    public function testExtractsAttachmentTokens(): void
    {
        $token = str_repeat('b', 64);
        $tokens = $this->validator->attachmentTokens([
            'type' => 'doc',
            'content' => [[
                'type' => 'image',
                'attrs' => ['src' => '/api/attachments/' . $token],
            ]],
        ]);

        self::assertSame([$token], $tokens);
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
                ['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'highlight']]],
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
