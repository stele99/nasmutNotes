<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Support\UrlValidator;

/**
 * Wandelt Markdown in das ProseMirror-JSON der Anwendung (FR-IMP-20).
 *
 * Bewusst ein eigener, kleiner Parser statt einer Markdown-Bibliothek: Gebraucht
 * wird genau die Teilmenge, die der Editor kennt (siehe ProseMirrorValidator).
 * Ein Umweg über HTML wäre hier ein Rückschritt — rohes HTML darf die Anwendung
 * ohnehin nie speichern, es müsste also direkt wieder zerlegt werden.
 */
final class MarkdownConverter
{
    /** Nur H1–H3 sind erlaubt; tiefere Ebenen werden auf H3 gezogen. */
    private const MAX_HEADING_LEVEL = 3;

    /** Escapebare Satzzeichen nach CommonMark. */
    private const ESCAPABLE = '!"#$%&\'()*+,\-.\/:;<=>?@\[\\\\\]^_`{|}~';

    /**
     * Trennt YAML-Frontmatter vom Rumpf. Erwartet werden nur die flachen
     * Schlüssel, die Exportwerkzeuge schreiben (`created`, `date`, `categories`).
     *
     * @return array{meta: array<string, string>, body: string}
     */
    public function splitFrontMatter(string $markdown): array
    {
        $markdown = $this->normalizeNewlines($markdown);
        if (preg_match('/\A---\n(.*?)\n---\n?/s', $markdown, $match) !== 1) {
            return ['meta' => [], 'body' => $markdown];
        }

        $meta = [];
        foreach (explode("\n", $match[1]) as $line) {
            if (preg_match('/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $pair) === 1) {
                $meta[strtolower($pair[1])] = trim($pair[2], " \t\"'");
            }
        }

        return ['meta' => $meta, 'body' => substr($markdown, strlen($match[0]))];
    }

    /**
     * Erste Überschrift des Rumpfs — dient als Titel, wenn der Dateiname nichts
     * Brauchbares hergibt.
     */
    public function firstHeading(string $body): ?string
    {
        if (preg_match('/^#{1,6}\s+(.+)$/m', $this->normalizeNewlines($body), $match) !== 1) {
            return null;
        }

        $text = $this->plainText($this->inline(trim($match[1]), []));

        return $text !== '' ? $text : null;
    }

    /**
     * Verweise auf Ressourcen, die das Exportwerkzeug gar nicht mitgeliefert hat
     * (`![[./_resources/…]]`). Sie zeigen ins Leere und würden sonst als
     * Zeichensalat im Text stehen bleiben.
     */
    public function countDeadResourceLinks(string $body): int
    {
        return preg_match_all('/!\\\\?\[\\\\?\[.*?\\\\?\]\\\\?\]/su', $body) ?: 0;
    }

    /**
     * @param callable(string $target, string $label, bool $asImage): ?array{
     *     kind: string, src?: string, width?: int, height?: int, text?: string
     * }|null $resolveAsset
     *     Wird für jedes Ziel aufgerufen, das keine http(s)-Adresse ist. `null`
     *     als Rückgabe bedeutet: unauflösbar, es bleibt der Beschriftungstext.
     * @return array<string, mixed> ProseMirror-Dokument
     */
    public function toDocument(string $markdown, ?callable $resolveAsset = null): array
    {
        $body = $this->stripDeadResourceLinks($this->normalizeNewlines($markdown));
        $lines = explode("\n", $body);
        $index = 0;
        $content = $this->blocks($lines, $index, count($lines), 0, $resolveAsset);

        return ['type' => 'doc', 'content' => $content];
    }

    private function normalizeNewlines(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    private function stripDeadResourceLinks(string $body): string
    {
        return preg_replace('/!\\\\?\[\\\\?\[.*?\\\\?\]\\\\?\]/su', '', $body) ?? $body;
    }

    /**
     * @param list<string> $lines
     * @param int $index Laufender Zeilenzeiger, wird fortgeschrieben.
     * @return list<array<string, mixed>>
     */
    private function blocks(array $lines, int &$index, int $end, int $minIndent, ?callable $resolveAsset): array
    {
        $nodes = [];

        while ($index < $end) {
            $line = $lines[$index];

            if (trim($line) === '') {
                ++$index;
                continue;
            }
            if ($this->indentOf($line) < $minIndent) {
                break;
            }

            $node = $this->fence($lines, $index, $end)
                ?? $this->heading($line, $index, $resolveAsset)
                ?? $this->horizontalRule($line, $index)
                ?? $this->quote($lines, $index, $end, $resolveAsset)
                ?? $this->table($lines, $index, $end, $resolveAsset);

            if ($node !== null) {
                $nodes[] = $node;
                continue;
            }

            if ($this->listMarker($line) !== null) {
                $nodes[] = $this->list($lines, $index, $end, $resolveAsset);
                continue;
            }

            foreach ($this->paragraph($lines, $index, $end, $resolveAsset) as $paragraphNode) {
                $nodes[] = $paragraphNode;
            }
        }

        return $nodes;
    }

    /**
     * @param list<string> $lines
     * @return array<string, mixed>|null
     */
    private function fence(array $lines, int &$index, int $end): ?array
    {
        if (preg_match('/^\s*(```|~~~)\s*([A-Za-z0-9_+-]*)\s*$/', $lines[$index], $match) !== 1) {
            return null;
        }

        $marker = $match[1];
        $language = $match[2] !== '' ? $match[2] : null;
        $code = [];
        ++$index;
        while ($index < $end && preg_match('/^\s*' . preg_quote($marker, '/') . '\s*$/', $lines[$index]) !== 1) {
            $code[] = $lines[$index];
            ++$index;
        }
        // Ein fehlendes Schlusszeichen darf nicht dazu führen, dass der Rest der
        // Notiz verschluckt wird — der Zeiger steht dann bereits am Ende.
        if ($index < $end) {
            ++$index;
        }

        $text = rtrim(implode("\n", $code), "\n");

        return [
            'type' => 'codeBlock',
            'attrs' => ['language' => $language],
            'content' => $text === '' ? [] : [['type' => 'text', 'text' => $text]],
        ];
    }

    /** @return array<string, mixed>|null */
    private function heading(string $line, int &$index, ?callable $resolveAsset): ?array
    {
        if (preg_match('/^\s{0,3}(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $match) !== 1) {
            return null;
        }
        ++$index;

        $level = min(strlen($match[1]), self::MAX_HEADING_LEVEL);
        $content = $this->inlineWithoutImages($match[2], $resolveAsset);

        return [
            'type' => 'heading',
            'attrs' => ['level' => $level],
            'content' => $content,
        ];
    }

    /** @return array<string, mixed>|null */
    private function horizontalRule(string $line, int &$index): ?array
    {
        if (preg_match('/^\s{0,3}([-*_])\s*(?:\1\s*){2,}$/', $line) !== 1) {
            return null;
        }
        ++$index;

        return ['type' => 'horizontalRule'];
    }

    /**
     * @param list<string> $lines
     * @return array<string, mixed>|null
     */
    private function quote(array $lines, int &$index, int $end, ?callable $resolveAsset): ?array
    {
        if (preg_match('/^\s{0,3}>\s?/', $lines[$index]) !== 1) {
            return null;
        }

        $inner = [];
        while ($index < $end && preg_match('/^\s{0,3}>\s?(.*)$/', $lines[$index], $match) === 1) {
            $inner[] = $match[1];
            ++$index;
        }

        $innerIndex = 0;
        $content = $this->blocks($inner, $innerIndex, count($inner), 0, $resolveAsset);

        return [
            'type' => 'blockquote',
            'content' => $content !== [] ? $content : [['type' => 'paragraph']],
        ];
    }

    /**
     * Tabellen im GitHub-Stil: Kopfzeile, Trennzeile, Datenzeilen.
     *
     * @param list<string> $lines
     * @return array<string, mixed>|null
     */
    private function table(array $lines, int &$index, int $end, ?callable $resolveAsset): ?array
    {
        if ($index + 1 >= $end
            || !$this->isTableRow($lines[$index])
            || preg_match('/^\s*\|?[\s:|-]*-[\s:|-]*\|?\s*$/', $lines[$index + 1]) !== 1
            || !str_contains($lines[$index + 1], '-')) {
            return null;
        }

        $header = $this->tableCells($lines[$index], 'tableHeader', $resolveAsset);
        $index += 2;

        $rows = [['type' => 'tableRow', 'content' => $header]];
        while ($index < $end && $this->isTableRow($lines[$index])) {
            $rows[] = [
                'type' => 'tableRow',
                'content' => $this->tableCells($lines[$index], 'tableCell', $resolveAsset),
            ];
            ++$index;
        }

        return ['type' => 'table', 'content' => $rows];
    }

    private function isTableRow(string $line): bool
    {
        return preg_match('/^\s*\|.*\|\s*$/', $line) === 1;
    }

    /** @return list<array<string, mixed>> */
    private function tableCells(string $line, string $cellType, ?callable $resolveAsset): array
    {
        $trimmed = trim($line);
        $trimmed = preg_replace('/^\||\|$/', '', $trimmed) ?? $trimmed;
        // Maskierte Trenner gehören zum Zelltext und dürfen nicht trennen.
        $parts = preg_split('/(?<!\\\\)\|/', $trimmed) ?: [];

        $cells = [];
        foreach ($parts as $part) {
            // Leere Zellen exportiert UpNote als "<br>"; ohne das Trimmen bliebe
            // in jeder davon eine sichtbare Leerzeile stehen.
            $inline = $this->trimInline($this->inlineWithoutImages(
                trim(str_replace('\\|', '|', $part)),
                $resolveAsset,
            ));
            $cells[] = [
                'type' => $cellType,
                'attrs' => ['colspan' => 1, 'rowspan' => 1, 'colwidth' => null],
                'content' => [$this->paragraphNode($inline)],
            ];
        }

        return $cells;
    }

    /** @return array{indent: int, kind: string, checked: bool, text: string}|null */
    private function listMarker(string $line): ?array
    {
        if (preg_match('/^(\s*)([-*+]|\d{1,9}[.)])\s+(.*)$/', $line, $match) !== 1) {
            return null;
        }

        $text = $match[3];
        $kind = preg_match('/^\d/', $match[2]) === 1 ? 'ordered' : 'bullet';
        $checked = false;
        if ($kind === 'bullet' && preg_match('/^\[([ xX])\]\s+(.*)$/', $text, $task) === 1) {
            $kind = 'task';
            $checked = strtolower($task[1]) === 'x';
            $text = $task[2];
        }

        return [
            'indent' => strlen($match[1]),
            'kind' => $kind,
            'checked' => $checked,
            'text' => $text,
        ];
    }

    /**
     * @param list<string> $lines
     * @return array<string, mixed>
     */
    private function list(array $lines, int &$index, int $end, ?callable $resolveAsset): array
    {
        $first = $this->listMarker($lines[$index]);
        if ($first === null) {
            ++$index;

            return ['type' => 'bulletList', 'content' => []];
        }
        $baseIndent = $first['indent'];
        $kind = $first['kind'];
        $items = [];

        while ($index < $end) {
            $marker = $this->listMarker($lines[$index]);
            if ($marker === null || $marker['indent'] < $baseIndent) {
                break;
            }
            // Tiefer eingerückt ohne vorheriges Element: gehört zur Unterliste
            // des zuletzt begonnenen Punkts, die weiter unten eingesammelt wird.
            if ($marker['indent'] > $baseIndent) {
                break;
            }
            // Ein Wechsel der Aufzählungsart beginnt eine neue Liste.
            if ($marker['kind'] !== $kind) {
                break;
            }

            ++$index;
            $itemLines = [$marker['text']];
            $childIndent = $baseIndent + 1;
            while ($index < $end) {
                $line = $lines[$index];
                if (trim($line) === '') {
                    // Leerzeile nur übernehmen, wenn der Punkt danach weitergeht.
                    if ($index + 1 < $end && $this->indentOf($lines[$index + 1]) >= $childIndent) {
                        $itemLines[] = '';
                        ++$index;
                        continue;
                    }
                    break;
                }
                if ($this->indentOf($line) < $childIndent) {
                    break;
                }
                $itemLines[] = substr($line, min($this->indentOf($line), $baseIndent + 2));
                ++$index;
            }

            $itemIndex = 0;
            $content = $this->blocks($itemLines, $itemIndex, count($itemLines), 0, $resolveAsset);
            if ($content === []) {
                $content = [['type' => 'paragraph']];
            }

            $items[] = $kind === 'task'
                ? ['type' => 'taskItem', 'attrs' => ['checked' => $marker['checked']], 'content' => $content]
                : ['type' => 'listItem', 'content' => $content];
        }

        return [
            'type' => match ($kind) {
                'task' => 'taskList',
                'ordered' => 'orderedList',
                default => 'bulletList',
            },
            'content' => $items,
        ];
    }

    /**
     * @param list<string> $lines
     * @return list<array<string, mixed>>
     */
    private function paragraph(array $lines, int &$index, int $end, ?callable $resolveAsset): array
    {
        $collected = [];
        while ($index < $end) {
            $line = $lines[$index];
            if (trim($line) === ''
                || $this->listMarker($line) !== null
                || preg_match('/^\s{0,3}(#{1,6}\s|>|```|~~~)/', $line) === 1
                || preg_match('/^\s{0,3}([-*_])\s*(?:\1\s*){2,}$/', $line) === 1
                // Eine Tabelle direkt unter einem Absatz ohne Leerzeile dazwischen
                // würde sonst als Fließtext eingesammelt.
                || ($this->isTableRow($line) && $index + 1 < $end && $this->isTableRow($lines[$index + 1]))) {
                break;
            }
            $collected[] = $line;
            ++$index;
        }

        if ($collected === []) {
            ++$index;

            return [];
        }

        $inline = [];
        foreach ($collected as $position => $line) {
            if ($position > 0) {
                $inline[] = ['type' => 'hardBreak'];
            }
            foreach ($this->inline(trim($line), [], $resolveAsset) as $node) {
                $inline[] = $node;
            }
        }

        return $this->splitBlockImages($inline);
    }

    /**
     * Bilder sind im Editor Blockknoten (`inline: false`). Steht ein Bild
     * zwischen Text, muss der Absatz an dieser Stelle geteilt werden.
     *
     * @param list<array<string, mixed>> $inline
     * @return list<array<string, mixed>>
     */
    private function splitBlockImages(array $inline): array
    {
        $nodes = [];
        $buffer = [];
        foreach ($inline as $node) {
            if (($node['type'] ?? '') !== 'image') {
                $buffer[] = $node;
                continue;
            }
            if ($this->hasVisibleContent($buffer)) {
                $nodes[] = $this->paragraphNode($this->trimInline($buffer));
            }
            $buffer = [];
            $nodes[] = $node;
        }
        if ($this->hasVisibleContent($buffer)) {
            $nodes[] = $this->paragraphNode($this->trimInline($buffer));
        }

        return $nodes;
    }

    /** @param list<array<string, mixed>> $inline */
    private function hasVisibleContent(array $inline): bool
    {
        foreach ($inline as $node) {
            if (($node['type'] ?? '') === 'text' && trim((string) $node['text']) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Führende und abschließende Umbrüche entfernen — sie entstehen beim
     * Herauslösen von Bildern und würden als leere Zeilen sichtbar bleiben.
     *
     * @param list<array<string, mixed>> $inline
     * @return list<array<string, mixed>>
     */
    private function trimInline(array $inline): array
    {
        while ($inline !== [] && ($inline[0]['type'] ?? '') === 'hardBreak') {
            array_shift($inline);
        }
        while ($inline !== [] && ($inline[count($inline) - 1]['type'] ?? '') === 'hardBreak') {
            array_pop($inline);
        }

        return array_values($inline);
    }

    /**
     * @param list<array<string, mixed>> $inline
     * @return array<string, mixed>
     */
    private function paragraphNode(array $inline): array
    {
        return $inline === []
            ? ['type' => 'paragraph']
            : ['type' => 'paragraph', 'content' => $inline];
    }

    /**
     * Für Überschriften und Tabellenzellen: Dort sind Bildknoten nicht erlaubt,
     * der Beschriftungstext tritt an ihre Stelle.
     *
     * @return list<array<string, mixed>>
     */
    private function inlineWithoutImages(string $text, ?callable $resolveAsset): array
    {
        return array_values(array_filter(
            $this->inline($text, [], $resolveAsset),
            static fn (array $node): bool => ($node['type'] ?? '') !== 'image',
        ));
    }

    /**
     * @param list<array<string, mixed>> $marks
     * @return list<array<string, mixed>>
     */
    private function inline(string $text, array $marks, ?callable $resolveAsset = null): array
    {
        $nodes = [];
        $buffer = '';
        $offset = 0;
        $length = strlen($text);

        // Der Puffer wird bewusst übergeben statt eingefangen: So bleibt für die
        // statische Analyse sichtbar, dass er zur Laufzeit gefüllt sein kann.
        $flush = function (string $pending) use (&$nodes, $marks): void {
            if ($pending === '') {
                return;
            }
            $node = ['type' => 'text', 'text' => $pending];
            if ($marks !== []) {
                $node['marks'] = $marks;
            }
            $nodes[] = $node;
        };

        while ($offset < $length) {
            if (preg_match('/\\\\([' . self::ESCAPABLE . '])/A', $text, $m, 0, $offset) === 1) {
                $buffer .= $m[1];
                $offset += strlen($m[0]);
                continue;
            }

            if (preg_match('/(`+)([^`]|[^`].*?[^`])\1(?!`)/Asu', $text, $m, 0, $offset) === 1) {
                $flush($buffer);
                $buffer = '';
                $nodes[] = [
                    'type' => 'text',
                    'text' => trim($m[2]),
                    'marks' => $this->withMark($marks, ['type' => 'code']),
                ];
                $offset += strlen($m[0]);
                continue;
            }

            if (preg_match('/<br\s*\/?>/Ai', $text, $m, 0, $offset) === 1) {
                $flush($buffer);
                $buffer = '';
                $nodes[] = ['type' => 'hardBreak'];
                $offset += strlen($m[0]);
                continue;
            }

            if (preg_match('/<(https?:\/\/[^>\s]+)>/A', $text, $m, 0, $offset) === 1) {
                $flush($buffer);
                $buffer = '';
                foreach ($this->linkNodes($m[1], $m[1], $marks, $resolveAsset) as $node) {
                    $nodes[] = $node;
                }
                $offset += strlen($m[0]);
                continue;
            }

            // Übrig gebliebenes HTML: Die Anwendung speichert nie Markup, der
            // Tag verschwindet, sein Textinhalt bleibt erhalten.
            if (preg_match('/<\/?[a-zA-Z][^>]*>/A', $text, $m, 0, $offset) === 1) {
                $offset += strlen($m[0]);
                continue;
            }

            $isImage = $text[$offset] === '!' && ($text[$offset + 1] ?? '') === '[';
            if ($isImage || $text[$offset] === '[') {
                $pattern = '/' . ($isImage ? '!' : '') . '\[((?:[^\[\]\\\\]|\\\\.)*)\]\('
                    . '\s*(<[^>]*>|[^)\s]*)(?:\s+"[^"]*")?\s*\)/As';
                if (preg_match($pattern, $text, $m, 0, $offset) === 1) {
                    $flush($buffer);
                    $buffer = '';
                    $target = trim($m[2], '<>');
                    foreach ($this->linkNodes($target, $m[1], $marks, $resolveAsset, $isImage) as $node) {
                        $nodes[] = $node;
                    }
                    $offset += strlen($m[0]);
                    continue;
                }
            }

            foreach ([
                ['/(\*\*|__)(?=\S)(.+?)(?<=\S)\1/Asu', ['type' => 'bold']],
                ['/(~~)(?=\S)(.+?)(?<=\S)\1/Asu', ['type' => 'strike']],
                ['/(\*)(?=\S)((?:[^*]|\*\*)+?)(?<=\S)\1(?!\*)/Asu', ['type' => 'italic']],
                ['/(?<![\p{L}\p{N}_])(_)(?=\S)(.+?)(?<=\S)\1(?![\p{L}\p{N}_])/Asu', ['type' => 'italic']],
            ] as [$pattern, $mark]) {
                if (preg_match($pattern, $text, $m, 0, $offset) === 1) {
                    $flush($buffer);
                    $buffer = '';
                    foreach ($this->inline($m[2], $this->withMark($marks, $mark), $resolveAsset) as $node) {
                        $nodes[] = $node;
                    }
                    $offset += strlen($m[0]);
                    continue 2;
                }
            }

            $buffer .= $text[$offset];
            ++$offset;
        }

        $flush($buffer);

        return $nodes;
    }

    /**
     * @param list<array<string, mixed>> $marks
     * @return list<array<string, mixed>>
     */
    private function linkNodes(
        string $target,
        string $label,
        array $marks,
        ?callable $resolveAsset,
        bool $asImage = false,
    ): array {
        $label = $this->unescape($label);

        // Auch für Bilder: Eine fremde Bild-URL kann der Editor nicht speichern
        // (Bilder müssen aus dem eigenen Anhangspeicher stammen), die Adresse
        // bleibt deshalb als Link erhalten, damit nichts verloren geht.
        if (UrlValidator::isValidHttpUrl($target)) {
            return $this->inline(
                $label !== '' ? $label : $target,
                $this->withMark($marks, ['type' => 'link', 'attrs' => ['href' => $target]]),
                $resolveAsset,
            );
        }

        $resolved = $resolveAsset !== null ? $resolveAsset($target, $label, $asImage) : null;

        if (is_array($resolved) && ($resolved['kind'] ?? '') === 'image') {
            $attrs = ['src' => (string) $resolved['src']];
            if ($label !== '') {
                $attrs['alt'] = mb_substr($label, 0, 500);
            }
            foreach (['width', 'height'] as $key) {
                if (isset($resolved[$key]) && is_int($resolved[$key])) {
                    $attrs[$key] = $resolved[$key];
                }
            }

            return [['type' => 'image', 'attrs' => $attrs]];
        }

        $text = is_array($resolved) && isset($resolved['text'])
            ? (string) $resolved['text']
            : ($label !== '' ? $label : $target);

        return $text === '' ? [] : $this->inline($text, $marks, $resolveAsset);
    }

    /**
     * @param list<array<string, mixed>> $marks
     * @param array<string, mixed> $mark
     * @return list<array<string, mixed>>
     */
    private function withMark(array $marks, array $mark): array
    {
        foreach ($marks as $existing) {
            if (($existing['type'] ?? '') === ($mark['type'] ?? '')) {
                return $marks;
            }
        }

        return [...$marks, $mark];
    }

    private function unescape(string $text): string
    {
        return preg_replace('/\\\\([' . self::ESCAPABLE . '])/', '$1', $text) ?? $text;
    }

    /** @param list<array<string, mixed>> $inline */
    private function plainText(array $inline): string
    {
        $parts = [];
        foreach ($inline as $node) {
            if (($node['type'] ?? '') === 'text') {
                $parts[] = (string) $node['text'];
            }
        }

        return trim(implode('', $parts));
    }

    private function indentOf(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
    }
}
