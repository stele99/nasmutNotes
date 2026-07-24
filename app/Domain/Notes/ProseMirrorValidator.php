<?php

declare(strict_types=1);

namespace App\Domain\Notes;

use App\Support\UrlValidator;

/**
 * Serverseitige Allowlist-Validierung des ProseMirror-JSON (FR-NOTE-01/03).
 * Rohes HTML wird nie gespeichert — nur dieser Knoten-/Mark-Baum.
 */
final class ProseMirrorValidator
{
    private const NODE_TYPES = [
        'doc', 'paragraph', 'heading', 'text',
        'bulletList', 'orderedList', 'listItem',
        'taskList', 'taskItem',
        'codeBlock', 'blockquote', 'horizontalRule', 'hardBreak',
        'table', 'tableRow', 'tableCell', 'tableHeader',
    ];

    private const MARK_TYPES = ['bold', 'italic', 'strike', 'code', 'link'];

    /** @param array<string, mixed> $doc */
    public function validate(array $doc): void
    {
        if (($doc['type'] ?? null) !== 'doc') {
            throw new NoteContentException('Wurzelelement muss vom Typ "doc" sein.');
        }

        $this->validateNode($doc);
    }

    /** @param array<string, mixed> $node */
    private function validateNode(array $node): void
    {
        $type = $node['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::NODE_TYPES, true)) {
            throw new NoteContentException("Nicht erlaubter Knotentyp: " . json_encode($type));
        }

        if ($type === 'heading') {
            $level = $node['attrs']['level'] ?? null;
            if (!is_int($level) || $level < 1 || $level > 3) {
                throw new NoteContentException('Überschriften sind nur in den Ebenen H1–H3 erlaubt.');
            }
        }

        if ($type === 'text') {
            if (!isset($node['text']) || !is_string($node['text'])) {
                throw new NoteContentException('Textknoten ohne gültigen Inhalt.');
            }
            $this->validateMarks($node['marks'] ?? []);

            return;
        }

        foreach ((array) ($node['content'] ?? []) as $child) {
            if (!is_array($child)) {
                throw new NoteContentException('Ungültige Kindknoten-Struktur.');
            }
            $this->validateNode($child);
        }
    }

    /** @param mixed $marks */
    private function validateMarks(mixed $marks): void
    {
        if (!is_array($marks)) {
            return;
        }

        foreach ($marks as $mark) {
            if (!is_array($mark) || !isset($mark['type']) || !in_array($mark['type'], self::MARK_TYPES, true)) {
                throw new NoteContentException('Nicht erlaubter Formatierungstyp.');
            }

            if ($mark['type'] === 'link') {
                $href = $mark['attrs']['href'] ?? null;
                if (!is_string($href) || !UrlValidator::isValidHttpUrl($href)) {
                    throw new NoteContentException('Links sind nur mit gültiger http(s)-URL erlaubt.');
                }
            }
        }
    }

    /** @param array<string, mixed> $doc */
    public function extractText(array $doc): string
    {
        $parts = [];
        $this->collectText($doc, $parts);

        return trim(preg_replace('/\n{3,}/', "\n\n", implode('', $parts)) ?? '');
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string> $parts
     */
    private function collectText(array $node, array &$parts): void
    {
        $type = $node['type'] ?? null;

        if ($type === 'text' && is_string($node['text'] ?? null)) {
            $parts[] = $node['text'];

            return;
        }

        foreach ((array) ($node['content'] ?? []) as $child) {
            if (is_array($child)) {
                $this->collectText($child, $parts);
            }
        }

        if (in_array($type, ['paragraph', 'heading', 'listItem', 'blockquote', 'codeBlock', 'tableRow'], true)) {
            $parts[] = "\n";
        }
    }
}
