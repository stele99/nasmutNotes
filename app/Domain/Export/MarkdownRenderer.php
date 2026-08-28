<?php

declare(strict_types=1);

namespace App\Domain\Export;

use App\Domain\Notes\ImageAnnotationValidator;

/**
 * Gegenstück zum MarkdownConverter des Imports: ProseMirror-JSON zurück nach
 * Markdown (FR-EXP-01/03).
 *
 * Die Ausgabe zielt bewusst auf das Format, das der Import wieder einlesen kann
 * (`**fett**`, `*kursiv*`, `~~durchgestrichen~~`, Backtick-Code, GFM-Tabellen) -
 * ein Export soll sich in dieselbe Anwendung zurückspielen lassen. Unterstrichen
 * kennt Markdown nicht; dafür bleibt Inline-HTML als ehrlichste Näherung.
 */
final class MarkdownRenderer
{
    /**
     * @param array<string, mixed>      $document
     * @param (callable(string): ?string)|null $resolveImage Bild-Token → relativer Pfad im Archiv
     */
    public function render(array $document, ?callable $resolveImage = null): string
    {
        $blocks = $this->blocks($this->children($document), $resolveImage);

        return rtrim(implode("\n\n", $blocks)) . "\n";
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     *
     * @return array<int, string>
     */
    private function blocks(array $nodes, ?callable $resolveImage): array
    {
        $blocks = [];
        foreach ($nodes as $node) {
            $rendered = $this->block($node, $resolveImage);
            if ($rendered !== '') {
                $blocks[] = $rendered;
            }
        }

        return $blocks;
    }

    /** @param array<string, mixed> $node */
    private function block(array $node, ?callable $resolveImage): string
    {
        return match ($node['type'] ?? '') {
            'paragraph' => $this->inline($this->children($node), $resolveImage),
            'heading' => $this->heading($node, $resolveImage),
            'bulletList' => $this->bulletList($node, $resolveImage),
            'orderedList' => $this->orderedList($node, $resolveImage),
            'taskList' => $this->taskList($node, $resolveImage),
            'codeBlock' => $this->codeBlock($node),
            'blockquote' => $this->blockquote($node, $resolveImage),
            'horizontalRule' => '---',
            'table' => $this->table($node, $resolveImage),
            // Ein Bild als eigener Block, nicht in einem Absatz verpackt.
            'image' => $this->image($node, $resolveImage),
            default => '',
        };
    }

    /** @param array<string, mixed> $node */
    private function heading(array $node, ?callable $resolveImage): string
    {
        $level = (int) ($node['attrs']['level'] ?? 1);
        $level = max(1, min(6, $level));

        return str_repeat('#', $level) . ' ' . $this->inline($this->children($node), $resolveImage);
    }

    /** @param array<string, mixed> $node */
    private function bulletList(array $node, ?callable $resolveImage): string
    {
        $lines = [];
        foreach ($this->children($node) as $item) {
            $lines[] = $this->listItem($item, '- ', $resolveImage);
        }

        return implode("\n", array_filter($lines, static fn (string $line): bool => $line !== ''));
    }

    /** @param array<string, mixed> $node */
    private function orderedList(array $node, ?callable $resolveImage): string
    {
        $number = (int) ($node['attrs']['start'] ?? 1);
        $number = max(1, $number);

        $lines = [];
        foreach ($this->children($node) as $item) {
            $rendered = $this->listItem($item, $number . '. ', $resolveImage);
            if ($rendered !== '') {
                $lines[] = $rendered;
                ++$number;
            }
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $node */
    private function taskList(array $node, ?callable $resolveImage): string
    {
        $lines = [];
        foreach ($this->children($node) as $item) {
            $checked = ($item['attrs']['checked'] ?? false) === true;
            $rendered = $this->listItem($item, $checked ? '- [x] ' : '- [ ] ', $resolveImage);
            if ($rendered !== '') {
                $lines[] = $rendered;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Der erste Block steht hinter dem Aufzählungszeichen, alle weiteren
     * (verschachtelte Listen, Folgeabsätze) rücken um dessen Breite ein.
     *
     * @param array<string, mixed> $item
     */
    private function listItem(array $item, string $marker, ?callable $resolveImage): string
    {
        $blocks = $this->blocks($this->children($item), $resolveImage);
        if ($blocks === []) {
            return rtrim($marker);
        }

        $indent = str_repeat(' ', mb_strlen($marker));
        $first = array_shift($blocks);
        $lines = [$marker . $this->indentAfterFirstLine($first, $indent)];

        foreach ($blocks as $block) {
            $lines[] = $this->indentAll($block, $indent);
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $node */
    private function codeBlock(array $node): string
    {
        $language = (string) ($node['attrs']['language'] ?? '');
        $code = $this->plainText($this->children($node));
        // Enthält der Code selbst eine Zaunlinie, muss der äußere Zaun länger sein.
        $fence = str_repeat('`', max(3, $this->longestBacktickRun($code) + 1));

        return $fence . $language . "\n" . $code . "\n" . $fence;
    }

    /** @param array<string, mixed> $node */
    private function blockquote(array $node, ?callable $resolveImage): string
    {
        $inner = implode("\n\n", $this->blocks($this->children($node), $resolveImage));
        if ($inner === '') {
            return '';
        }

        $lines = array_map(
            static fn (string $line): string => $line === '' ? '>' : '> ' . $line,
            explode("\n", $inner),
        );

        return implode("\n", $lines);
    }

    /**
     * GFM-Tabelle. Markdown kennt in Zellen nur Fließtext, deshalb wird der
     * Zellinhalt auf eine Zeile gebracht.
     *
     * @param array<string, mixed> $node
     */
    private function table(array $node, ?callable $resolveImage): string
    {
        $rows = [];
        foreach ($this->children($node) as $row) {
            if (($row['type'] ?? '') !== 'tableRow') {
                continue;
            }
            $cells = [];
            foreach ($this->children($row) as $cell) {
                $cells[] = $this->tableCell($cell, $resolveImage);
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            return '';
        }

        $columns = max(array_map('count', $rows));
        $lines = [];
        foreach ($rows as $index => $cells) {
            $cells = array_pad($cells, $columns, '');
            $lines[] = '| ' . implode(' | ', $cells) . ' |';
            if ($index === 0) {
                // Ohne Trennzeile ist es für jeden Markdown-Leser keine Tabelle.
                $lines[] = '| ' . implode(' | ', array_fill(0, $columns, '---')) . ' |';
            }
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $cell */
    private function tableCell(array $cell, ?callable $resolveImage): string
    {
        $blocks = $this->blocks($this->children($cell), $resolveImage);
        $text = trim(implode(' ', $blocks));
        $text = str_replace(["\n", '|'], [' ', '\\|'], $text);

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     */
    private function inline(array $nodes, ?callable $resolveImage): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= match ($node['type'] ?? '') {
                'text' => $this->text($node),
                'hardBreak' => "  \n",
                'image' => $this->image($node, $resolveImage),
                default => '',
            };
        }

        return trim($out, " \t");
    }

    /** @param array<string, mixed> $node */
    private function text(array $node): string
    {
        $text = (string) ($node['text'] ?? '');
        if ($text === '') {
            return '';
        }

        $marks = is_array($node['marks'] ?? null) ? $node['marks'] : [];
        $types = [];
        $href = null;
        foreach ($marks as $mark) {
            $type = is_array($mark) ? (string) ($mark['type'] ?? '') : '';
            if ($type === '') {
                continue;
            }
            $types[$type] = true;
            if ($type === 'link') {
                $href = (string) ($mark['attrs']['href'] ?? '');
            }
        }

        // Code ist wörtlich - Escaping würde die Backslashes sichtbar machen.
        if (isset($types['code'])) {
            $fence = str_repeat('`', $this->longestBacktickRun($text) + 1);
            $padding = str_starts_with($text, '`') || str_ends_with($text, '`') ? ' ' : '';
            $out = $fence . $padding . $text . $padding . $fence;
        } else {
            $out = $this->escape($text);
        }

        // Reihenfolge von innen nach außen, damit die Zeichen sauber schachteln.
        if (isset($types['strike'])) {
            $out = $this->wrap($out, '~~');
        }
        if (isset($types['italic'])) {
            $out = $this->wrap($out, '*');
        }
        if (isset($types['bold'])) {
            $out = $this->wrap($out, '**');
        }
        if (isset($types['underline'])) {
            $out = $this->wrap($out, '<u>', '</u>');
        }
        if ($href !== null && $href !== '') {
            $out = '[' . $out . '](' . $this->url($href) . ')';
        }

        return $out;
    }

    /**
     * Auszeichnungen dürfen kein Leerzeichen einschließen - `** fett **` bliebe
     * bei jedem Markdown-Leser wörtlich stehen.
     */
    private function wrap(string $text, string $open, ?string $close = null): string
    {
        $close ??= $open;
        if (trim($text) === '') {
            return $text;
        }

        $leading = '';
        $trailing = '';
        if (preg_match('/^(\s*)(.*?)(\s*)$/su', $text, $match) === 1) {
            $leading = $match[1];
            $trailing = $match[3];
            $text = $match[2];
        }

        return $leading . $open . $text . $close . $trailing;
    }

    /** @param array<string, mixed> $node */
    private function image(array $node, ?callable $resolveImage): string
    {
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        $src = (string) ($attrs['src'] ?? '');
        $alt = $this->escape((string) ($attrs['alt'] ?? ''));

        $target = null;
        if ($resolveImage !== null && preg_match('#^/api/attachments/([a-f0-9]{64})$#', $src, $matches) === 1) {
            $target = $resolveImage($matches[1]);
        } elseif (preg_match('#^https?://#i', $src) === 1) {
            $target = $src;
        }

        // Ein Bild, dessen Datei fehlt, wird nicht als toter Link ausgegeben.
        if ($target === null) {
            return '';
        }

        $markdown = '![' . $alt . '](' . $this->url($target) . ')';
        $texts = ImageAnnotationValidator::texts($attrs['annotations'] ?? null);
        if ($texts !== []) {
            // Annotationen lassen sich in Markdown nicht darstellen, ohne sie
            // einzubrennen - was dieses Konzept ausschließt. Damit trotzdem
            // nichts verloren geht, wandern die Texte unter das Bild.
            $labels = [];
            foreach ($texts as $index => $text) {
                $labels[] = ($index + 1) . '. ' . $this->escape(str_replace("\n", ' ', $text));
            }
            $markdown .= "\n\n_Bildnotizen: " . implode(' · ', $labels) . '_';
        }

        return $markdown;
    }

    private function url(string $url): string
    {
        // Spitze Klammern sind die einzige Form, die Leerzeichen und Klammern
        // in einem Markdown-Ziel zuverlässig zusammenhält.
        return preg_match('/[ ()<>]/', $url) === 1
            ? '<' . str_replace(['<', '>'], ['%3C', '%3E'], $url) . '>'
            : $url;
    }

    private function escape(string $text): string
    {
        $escaped = preg_replace('/([\\\\`*\[\]])/u', '\\\\$1', $text) ?? $text;
        // Unterstriche nur an Wortgrenzen: `snake_case` soll lesbar bleiben.
        $escaped = preg_replace('/(?<![\p{L}\p{N}])_|_(?![\p{L}\p{N}])/u', '\\\\_', $escaped) ?? $escaped;
        $escaped = str_replace('~~', '\\~\\~', $escaped);

        // Zeilenanfänge, die sonst als Block gelesen würden.
        return preg_replace('/^(\s*)([#>+]|[-]|\d+\.)(\s)/u', '$1\\\\$2$3', $escaped) ?? $escaped;
    }

    /** @param array<int, array<string, mixed>> $nodes */
    private function plainText(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'text') {
                $out .= (string) ($node['text'] ?? '');
            } elseif (($node['type'] ?? '') === 'hardBreak') {
                $out .= "\n";
            } else {
                $out .= $this->plainText($this->children($node));
            }
        }

        return $out;
    }

    private function longestBacktickRun(string $text): int
    {
        preg_match_all('/`+/', $text, $matches);
        $longest = 0;
        foreach ($matches[0] as $run) {
            $longest = max($longest, strlen($run));
        }

        return $longest;
    }

    private function indentAfterFirstLine(string $block, string $indent): string
    {
        $lines = explode("\n", $block);
        $first = array_shift($lines) ?? '';
        if ($lines === []) {
            return $first;
        }

        return $first . "\n" . implode("\n", array_map(
            static fn (string $line): string => $line === '' ? '' : $indent . $line,
            $lines,
        ));
    }

    private function indentAll(string $block, string $indent): string
    {
        return implode("\n", array_map(
            static fn (string $line): string => $line === '' ? '' : $indent . $line,
            explode("\n", $block),
        ));
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<int, array<string, mixed>>
     */
    private function children(array $node): array
    {
        $content = $node['content'] ?? null;
        if (!is_array($content)) {
            return [];
        }

        return array_values(array_filter($content, 'is_array'));
    }
}
