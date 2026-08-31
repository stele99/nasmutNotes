<?php

declare(strict_types=1);

namespace App\Domain\Notes;

/**
 * Allowlist-Prüfung der Bild-Annotationen (FR-ANNO-05). Gegenstück zu
 * resources/js/editor/annotations/schema.js - die Typtabelle steht dort
 * wortgleich und muss mit dieser hier zusammen geändert werden.
 *
 * Der Client bereinigt nachsichtig, dieser Validator lehnt streng ab: Ein
 * unbekannter Schlüssel ist ein Fehler und kein stiller Verlust. Sonst
 * driften beide Seiten auseinander, ohne dass es jemandem auffällt.
 */
final class ImageAnnotationValidator
{
    public const MAX_ITEMS = 200;
    public const MAX_POINTS = 400;
    public const MAX_TEXT_LENGTH = 500;
    public const MAX_TEXT_LINES = 12;
    public const MAX_LABEL_LENGTH = 40;
    public const MAX_BYTES_PER_IMAGE = 40_000;
    public const MAX_BYTES_PER_DOCUMENT = 300_000;
    public const MAX_SPACE = 20_000;

    private const COORD_LIMIT = 100_000;
    private const VERSION = 1;
    private const HEAD_VALUES = ['none', 'end', 'both'];

    /**
     * @var array<string, array{num: string[], pos: string[], points?: bool,
     *     head?: bool, dash?: bool, fill?: bool, text?: bool, label?: bool}>
     */
    private const TYPES = [
        'pen' => ['num' => ['w'], 'pos' => ['w'], 'points' => true],
        'line' => [
            'num' => ['w', 'x1', 'y1', 'x2', 'y2'],
            'pos' => ['w'],
            'head' => true,
            'dash' => true,
        ],
        'rect' => ['num' => ['w', 'x', 'y', 'rw', 'rh'], 'pos' => ['w', 'rw', 'rh'], 'fill' => true],
        'ellipse' => ['num' => ['w', 'x', 'y', 'rw', 'rh'], 'pos' => ['w', 'rw', 'rh'], 'fill' => true],
        'text' => ['num' => ['x', 'y', 's', 'bw', 'bh'], 'pos' => ['s'], 'fill' => true, 'text' => true],
        'rules' => [
            'num' => ['w', 'x', 'y', 'rw', 'rh', 'gap'],
            'pos' => ['w', 'rw', 'rh', 'gap'],
        ],
        'marker' => ['num' => ['x', 'y', 'r', 'n'], 'pos' => ['r', 'n']],
        'mask' => ['num' => ['x', 'y', 'rw', 'rh'], 'pos' => ['rw', 'rh']],
        'dim' => [
            'num' => ['w', 'x1', 'y1', 'x2', 'y2', 's', 'bw', 'bh'],
            'pos' => ['w', 's'],
            'fill' => true,
            'label' => true,
        ],
    ];

    /**
     * @return int Byte-Größe des serialisierten Objekts, damit der Aufrufer
     *             das Dokumentbudget aufaddieren kann.
     *
     * @throws NoteContentException
     */
    public function validate(mixed $value): int
    {
        if (!is_array($value)) {
            throw new NoteContentException('Bildnotizen müssen ein Objekt sein.');
        }
        if (($value['v'] ?? null) !== self::VERSION) {
            throw new NoteContentException('Unbekannte Fassung der Bildnotizen.');
        }
        foreach (array_keys($value) as $key) {
            if (!in_array($key, ['v', 'space', 'items'], true)) {
                throw new NoteContentException("Unerlaubtes Feld in den Bildnotizen: {$key}.");
            }
        }

        $this->validateSpace($value['space'] ?? null);

        $items = $value['items'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            throw new NoteContentException('Bildnotizen brauchen eine Liste von Elementen.');
        }
        if (count($items) > self::MAX_ITEMS) {
            throw new NoteContentException(
                'Ein Bild darf höchstens ' . self::MAX_ITEMS . ' Notizelemente tragen.',
            );
        }

        $ids = [];
        foreach ($items as $item) {
            $id = $this->validateItem($item);
            if (isset($ids[$id])) {
                throw new NoteContentException('Doppelte Kennung in den Bildnotizen.');
            }
            $ids[$id] = true;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $bytes = $encoded === false ? PHP_INT_MAX : strlen($encoded);
        if ($bytes > self::MAX_BYTES_PER_IMAGE) {
            throw new NoteContentException('Die Bildnotizen eines Bildes sind zu umfangreich.');
        }

        return $bytes;
    }

    private function validateSpace(mixed $space): void
    {
        if (!is_array($space)) {
            throw new NoteContentException('Bildnotizen ohne Bezugsgröße.');
        }
        foreach (['w', 'h'] as $key) {
            $value = $space[$key] ?? null;
            if (!is_int($value) || $value < 1 || $value > self::MAX_SPACE) {
                throw new NoteContentException("Ungültige Bezugsgröße der Bildnotizen: {$key}.");
            }
        }
        if (count($space) !== 2) {
            throw new NoteContentException('Unerlaubtes Feld in der Bezugsgröße der Bildnotizen.');
        }
    }

    private function validateItem(mixed $item): string
    {
        if (!is_array($item)) {
            throw new NoteContentException('Ungültiges Element in den Bildnotizen.');
        }

        $type = $item['t'] ?? null;
        if (!is_string($type) || !isset(self::TYPES[$type])) {
            throw new NoteContentException('Unbekannter Typ in den Bildnotizen: ' . json_encode($type));
        }
        $spec = self::TYPES[$type];

        $id = $item['id'] ?? null;
        if (!is_string($id) || preg_match('/^[a-z0-9]{8}$/', $id) !== 1) {
            throw new NoteContentException('Ungültige Kennung in den Bildnotizen.');
        }

        $this->validateColor($item['c'] ?? null, 'Strichfarbe');

        $allowed = array_merge(['id', 't', 'c', 'o'], $spec['num']);
        if ($spec['fill'] ?? false) {
            $allowed[] = 'f';
        }
        if ($spec['head'] ?? false) {
            $allowed[] = 'head';
        }
        if ($spec['dash'] ?? false) {
            $allowed[] = 'd';
        }
        if (($spec['text'] ?? false) || ($spec['label'] ?? false)) {
            $allowed[] = 'text';
        }
        if ($spec['points'] ?? false) {
            $allowed[] = 'p';
        }
        foreach (array_keys($item) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new NoteContentException("Unerlaubtes Feld in den Bildnotizen: {$key}.");
            }
        }

        if (array_key_exists('o', $item)) {
            $opacity = $item['o'];
            if ((!is_int($opacity) && !is_float($opacity)) || $opacity < 0.05 || $opacity > 1) {
                throw new NoteContentException('Ungültige Deckkraft in den Bildnotizen.');
            }
        }

        foreach ($spec['num'] as $key) {
            $value = $item[$key] ?? null;
            if (!is_int($value) && !is_float($value)) {
                throw new NoteContentException("Ungültiger Zahlenwert in den Bildnotizen: {$key}.");
            }
            if (!is_finite((float) $value) || abs((float) $value) > self::COORD_LIMIT) {
                throw new NoteContentException("Zahlenwert außerhalb des Bereichs: {$key}.");
            }
            if (in_array($key, $spec['pos'], true) && (float) $value <= 0) {
                throw new NoteContentException("Wert muss größer als 0 sein: {$key}.");
            }
        }

        if ($type === 'marker') {
            $number = $item['n'];
            if (!is_int($number) || $number < 1 || $number > 99) {
                throw new NoteContentException('Ungültige Nummer in den Bildnotizen.');
            }
        }

        if ($spec['fill'] ?? false) {
            $fill = $item['f'] ?? null;
            if ($fill !== null) {
                $this->validateColor($fill, 'Füllfarbe');
            }
        }

        if (($spec['head'] ?? false) && array_key_exists('head', $item)
            && !in_array($item['head'], self::HEAD_VALUES, true)) {
            throw new NoteContentException('Ungültige Pfeilspitze in den Bildnotizen.');
        }

        if (($spec['dash'] ?? false) && array_key_exists('d', $item) && $item['d'] !== true) {
            throw new NoteContentException('Ungültige Strichelung in den Bildnotizen.');
        }

        if ($spec['text'] ?? false) {
            $this->validateText($item['text'] ?? null);
        }

        // Die Beschriftung eines Maßbands ist freiwillig: Die Maßlinie steht
        // auch ohne sie. Ist sie da, muss sie einzeilig und kurz sein.
        if (($spec['label'] ?? false) && array_key_exists('text', $item)) {
            $this->validateLabel($item['text']);
        }

        if ($spec['points'] ?? false) {
            $this->validatePoints($item['p'] ?? null);
        }

        return $id;
    }

    private function validateColor(mixed $value, string $label): void
    {
        if (!is_string($value) || preg_match('/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $value) !== 1) {
            // Nur Hex-Notation: Funktionsschreibweisen und Farbnamen könnten
            // im SVG-Attribut zu etwas anderem als einer Farbe werden.
            throw new NoteContentException("Ungültige {$label} in den Bildnotizen.");
        }
    }

    private function validateText(mixed $value): void
    {
        if (!is_string($value) || trim($value) === '') {
            throw new NoteContentException('Textelement ohne Inhalt in den Bildnotizen.');
        }
        if (mb_strlen($value) > self::MAX_TEXT_LENGTH) {
            throw new NoteContentException('Text in den Bildnotizen ist zu lang.');
        }
        if (str_contains($value, "\r") || substr_count($value, "\n") >= self::MAX_TEXT_LINES) {
            throw new NoteContentException('Text in den Bildnotizen hat zu viele Zeilen.');
        }
    }

    private function validateLabel(mixed $value): void
    {
        if (!is_string($value) || trim($value) === '') {
            throw new NoteContentException('Maßband ohne Länge in den Bildnotizen.');
        }
        if (mb_strlen($value) > self::MAX_LABEL_LENGTH) {
            throw new NoteContentException('Die Länge am Maßband ist zu lang.');
        }
        if (preg_match('/[\r\n]/', $value) === 1) {
            throw new NoteContentException('Die Länge am Maßband ist einzeilig.');
        }
    }

    private function validatePoints(mixed $value): void
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new NoteContentException('Freihandpfad ohne Punkte.');
        }
        if (count($value) > self::MAX_POINTS) {
            throw new NoteContentException('Freihandpfad mit zu vielen Punkten.');
        }
        foreach ($value as $point) {
            if (!is_array($point) || !array_is_list($point) || count($point) !== 2) {
                throw new NoteContentException('Ungültiger Punkt im Freihandpfad.');
            }
            foreach ($point as $coordinate) {
                if ((!is_int($coordinate) && !is_float($coordinate))
                    || !is_finite((float) $coordinate)
                    || abs((float) $coordinate) > self::COORD_LIMIT) {
                    throw new NoteContentException('Punkt außerhalb des Bereichs im Freihandpfad.');
                }
            }
        }
    }

    /**
     * Texte in Lesereihenfolge - für die Volltextsuche (ProseMirrorValidator)
     * und den Markdown-Export.
     *
     * @return string[]
     */
    public static function texts(mixed $value): array
    {
        if (!is_array($value) || !is_array($value['items'] ?? null)) {
            return [];
        }

        $texts = [];
        foreach ($value['items'] as $item) {
            if (!is_array($item) || !is_string($item['text'] ?? null)) {
                continue;
            }
            if (in_array($item['t'] ?? null, ['text', 'dim'], true)) {
                $texts[] = $item['text'];
            }
        }

        return $texts;
    }
}
