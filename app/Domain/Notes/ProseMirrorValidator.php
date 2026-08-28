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
    /**
     * Vorgabewert statt DI-Eintrag: Die Tests erzeugen den Validator an
     * mehreren Stellen mit `new ProseMirrorValidator()`, und PHP-DI verdrahtet
     * die Abhängigkeit im Betrieb ohnehin selbst.
     */
    public function __construct(
        private readonly ImageAnnotationValidator $annotations = new ImageAnnotationValidator(),
    ) {
    }

    /** Summe aller Annotations-Bytes des gerade geprüften Dokuments. */
    private int $annotationBytes = 0;

    private const NODE_TYPES = [
        'doc', 'paragraph', 'heading', 'text',
        'bulletList', 'orderedList', 'listItem',
        'taskList', 'taskItem',
        'codeBlock', 'blockquote', 'horizontalRule', 'hardBreak',
        'table', 'tableRow', 'tableCell', 'tableHeader',
        'image',
    ];

    private const MARK_TYPES = ['bold', 'italic', 'underline', 'strike', 'code', 'link'];

    /** @param array<string, mixed> $doc */
    public function validate(array $doc): void
    {
        if (($doc['type'] ?? null) !== 'doc') {
            throw new NoteContentException('Wurzelelement muss vom Typ "doc" sein.');
        }

        $this->annotationBytes = 0;
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

        if ($type === 'image') {
            $this->validateImage($node['attrs'] ?? null);

            return;
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
     * @param array<string, mixed> $doc
     * @return string[]
     */
    public function attachmentTokens(array $doc): array
    {
        $tokens = [];
        $this->collectAttachmentTokens($doc, $tokens);

        return array_values(array_unique($tokens));
    }

    private function validateImage(mixed $attrs): void
    {
        if (!is_array($attrs)) {
            throw new NoteContentException('Bildknoten ohne gültige Attribute.');
        }

        $src = $attrs['src'] ?? null;
        if (!is_string($src) || preg_match('#^/api/attachments/[a-f0-9]{64}$#', $src) !== 1) {
            throw new NoteContentException('Bilder müssen aus dem geschützten Attachment-Speicher stammen.');
        }

        foreach (['alt', 'title'] as $key) {
            $value = $attrs[$key] ?? null;
            if ($value !== null && (!is_string($value) || mb_strlen($value) > 500)) {
                throw new NoteContentException("Ungültiges Bildattribut: {$key}.");
            }
        }

        foreach (['width', 'height'] as $key) {
            $value = $attrs[$key] ?? null;
            if ($value !== null && (!is_int($value) && !is_float($value) || $value < 1 || $value > 20_000)) {
                throw new NoteContentException("Ungültiges Bildattribut: {$key}.");
            }
        }

        $annotations = $attrs['annotations'] ?? null;
        if ($annotations !== null) {
            $this->annotationBytes += $this->annotations->validate($annotations);
            if ($this->annotationBytes > ImageAnnotationValidator::MAX_BYTES_PER_DOCUMENT) {
                throw new NoteContentException(
                    'Die Bildnotizen dieser Notiz sind insgesamt zu umfangreich.',
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string[] $tokens
     */
    private function collectAttachmentTokens(array $node, array &$tokens): void
    {
        if (($node['type'] ?? null) === 'image') {
            $src = $node['attrs']['src'] ?? null;
            if (is_string($src) && preg_match('#^/api/attachments/([a-f0-9]{64})$#', $src, $matches) === 1) {
                $tokens[] = $matches[1];
            }
        }

        foreach ((array) ($node['content'] ?? []) as $child) {
            if (is_array($child)) {
                $this->collectAttachmentTokens($child, $tokens);
            }
        }
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

        if ($type === 'image') {
            foreach (ImageAnnotationValidator::texts($node['attrs']['annotations'] ?? null) as $text) {
                $parts[] = $text . "\n";
            }
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
