<?php

declare(strict_types=1);

namespace App\Domain\Notes;

/**
 * Serverseitiges Overlay-SVG für die öffentliche Freigabe und den Export
 * (FR-ANNO-07/11). Zwilling von resources/js/editor/annotations/render.js;
 * für dasselbe Modell entsteht dasselbe Markup.
 *
 * Der Aufrufer liefert nur Daten, die ImageAnnotationValidator bereits
 * passiert haben. Trotzdem wird hier jeder Wert erneut gecastet und jeder
 * Text maskiert - das ist die zweite Verteidigungslinie, nicht die erste.
 */
final class ImageAnnotationSvgRenderer
{
    private const FONT_STACK = "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";
    private const MAX_RULE_LINES = 200;

    /** @param array<string, mixed>|null $annotations */
    public function render(mixed $annotations): string
    {
        if (!is_array($annotations) || !is_array($annotations['items'] ?? null)) {
            return '';
        }
        $space = $annotations['space'] ?? null;
        if (!is_array($space)) {
            return '';
        }
        $width = max(1, (int) ($space['w'] ?? 0));
        $height = max(1, (int) ($space['h'] ?? 0));

        $body = '';
        foreach ($annotations['items'] as $item) {
            if (is_array($item)) {
                $body .= $this->item($item);
            }
        }
        if ($body === '') {
            return '';
        }

        return '<svg viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none"'
            . ' aria-hidden="true" focusable="false">' . $body . '</svg>';
    }

    /** @param array<string, mixed> $item */
    private function item(array $item): string
    {
        return match ($item['t'] ?? '') {
            'pen' => $this->pen($item),
            'line' => $this->line($item),
            'rect' => $this->rect($item),
            'ellipse' => $this->ellipse($item),
            'text' => $this->text($item),
            'rules' => $this->rules($item),
            'marker' => $this->marker($item),
            'mask' => $this->mask($item),
            default => '',
        };
    }

    private function num(mixed $value): string
    {
        $number = is_int($value) || is_float($value) ? (float) $value : 0.0;
        if (!is_finite($number)) {
            $number = 0.0;
        }

        return rtrim(rtrim(number_format(round($number, 1), 1, '.', ''), '0'), '.') ?: '0';
    }

    private function color(mixed $value): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $value) === 1
            ? $value
            : '#000000';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string, mixed> $item */
    private function opacity(array $item): string
    {
        $value = $item['o'] ?? null;
        if ((!is_int($value) && !is_float($value)) || (float) $value >= 1.0) {
            return '';
        }

        return ' opacity="' . $this->num(max(0.0, (float) $value)) . '"';
    }

    /** @param array<string, mixed> $item */
    private function stroke(array $item): string
    {
        return ' stroke="' . $this->color($item['c'] ?? null) . '"'
            . ' stroke-width="' . $this->num($item['w'] ?? 1) . '"'
            . ' stroke-linecap="round" stroke-linejoin="round" fill="none"';
    }

    /** @param array<string, mixed> $item */
    private function pen(array $item): string
    {
        $points = is_array($item['p'] ?? null) ? $item['p'] : [];
        if ($points === []) {
            return '';
        }
        if (count($points) === 1) {
            $point = $points[0];

            return '<circle cx="' . $this->num($point[0] ?? 0) . '" cy="' . $this->num($point[1] ?? 0) . '"'
                . ' r="' . $this->num(((float) ($item['w'] ?? 1)) / 2) . '"'
                . ' fill="' . $this->color($item['c'] ?? null) . '"' . $this->opacity($item) . '/>';
        }

        $path = '';
        foreach ($points as $index => $point) {
            if (!is_array($point)) {
                continue;
            }
            $path .= ($index === 0 ? 'M ' : ' L ') . $this->num($point[0] ?? 0) . ' ' . $this->num($point[1] ?? 0);
        }

        return '<path d="' . $path . '"' . $this->stroke($item) . $this->opacity($item) . '/>';
    }

    /**
     * Pfeilspitzen als eigener Pfad statt als <marker>: Ein <marker> braucht
     * eine ID in <defs>, und auf einer Seite mit mehreren annotierten Bildern
     * kollidieren diese IDs.
     *
     * @param array<string, mixed> $item
     */
    private function arrowHead(array $item, float $x, float $y, float $angle): string
    {
        $size = max(((float) ($item['w'] ?? 1)) * 4, 12.0);
        $spread = M_PI / 7;
        $ax = $x - $size * cos($angle - $spread);
        $ay = $y - $size * sin($angle - $spread);
        $bx = $x - $size * cos($angle + $spread);
        $by = $y - $size * sin($angle + $spread);

        return '<path d="M ' . $this->num($ax) . ' ' . $this->num($ay)
            . ' L ' . $this->num($x) . ' ' . $this->num($y)
            . ' L ' . $this->num($bx) . ' ' . $this->num($by) . '"'
            . $this->stroke($item) . $this->opacity($item) . '/>';
    }

    /** @param array<string, mixed> $item */
    private function line(array $item): string
    {
        $x1 = (float) ($item['x1'] ?? 0);
        $y1 = (float) ($item['y1'] ?? 0);
        $x2 = (float) ($item['x2'] ?? 0);
        $y2 = (float) ($item['y2'] ?? 0);
        $width = (float) ($item['w'] ?? 1);
        $dash = ($item['d'] ?? null) === true
            ? ' stroke-dasharray="' . $this->num($width * 3) . ' ' . $this->num($width * 2) . '"'
            : '';

        $markup = '<path d="M ' . $this->num($x1) . ' ' . $this->num($y1)
            . ' L ' . $this->num($x2) . ' ' . $this->num($y2) . '"'
            . $this->stroke($item) . $dash . $this->opacity($item) . '/>';

        $head = $item['head'] ?? 'none';
        $angle = atan2($y2 - $y1, $x2 - $x1);
        if ($head === 'end' || $head === 'both') {
            $markup .= $this->arrowHead($item, $x2, $y2, $angle);
        }
        if ($head === 'both') {
            $markup .= $this->arrowHead($item, $x1, $y1, $angle + M_PI);
        }

        return $markup;
    }

    /** @param array<string, mixed> $item */
    private function rect(array $item): string
    {
        $fill = ($item['f'] ?? null) === null ? 'none' : $this->color($item['f']);

        return '<rect x="' . $this->num($item['x'] ?? 0) . '" y="' . $this->num($item['y'] ?? 0) . '"'
            . ' width="' . $this->num($item['rw'] ?? 0) . '" height="' . $this->num($item['rh'] ?? 0) . '"'
            . ' fill="' . $fill . '" stroke="' . $this->color($item['c'] ?? null) . '"'
            . ' stroke-width="' . $this->num($item['w'] ?? 1) . '"' . $this->opacity($item) . '/>';
    }

    /** @param array<string, mixed> $item */
    private function ellipse(array $item): string
    {
        $x = (float) ($item['x'] ?? 0);
        $y = (float) ($item['y'] ?? 0);
        $rw = (float) ($item['rw'] ?? 0);
        $rh = (float) ($item['rh'] ?? 0);
        $fill = ($item['f'] ?? null) === null ? 'none' : $this->color($item['f']);

        return '<ellipse cx="' . $this->num($x + $rw / 2) . '" cy="' . $this->num($y + $rh / 2) . '"'
            . ' rx="' . $this->num($rw / 2) . '" ry="' . $this->num($rh / 2) . '"'
            . ' fill="' . $fill . '" stroke="' . $this->color($item['c'] ?? null) . '"'
            . ' stroke-width="' . $this->num($item['w'] ?? 1) . '"' . $this->opacity($item) . '/>';
    }

    /** @param array<string, mixed> $item */
    private function mask(array $item): string
    {
        return '<rect x="' . $this->num($item['x'] ?? 0) . '" y="' . $this->num($item['y'] ?? 0) . '"'
            . ' width="' . $this->num($item['rw'] ?? 0) . '" height="' . $this->num($item['rh'] ?? 0) . '"'
            . ' fill="' . $this->color($item['c'] ?? null) . '"' . $this->opacity($item) . '/>';
    }

    /** @param array<string, mixed> $item */
    private function rules(array $item): string
    {
        $gap = (float) ($item['gap'] ?? 0);
        if ($gap <= 0) {
            return '';
        }
        $x = (float) ($item['x'] ?? 0);
        $y = (float) ($item['y'] ?? 0);
        $rw = (float) ($item['rw'] ?? 0);
        $count = min(self::MAX_RULE_LINES, (int) floor(((float) ($item['rh'] ?? 0)) / $gap));

        $markup = '';
        for ($index = 1; $index <= $count; ++$index) {
            $lineY = $y + $index * $gap;
            $markup .= '<path d="M ' . $this->num($x) . ' ' . $this->num($lineY)
                . ' L ' . $this->num($x + $rw) . ' ' . $this->num($lineY) . '"'
                . $this->stroke($item) . $this->opacity($item) . '/>';
        }

        return $markup;
    }

    /** @param array<string, mixed> $item */
    private function marker(array $item): string
    {
        $color = $this->color($item['c'] ?? null);
        $radius = (float) ($item['r'] ?? 1);

        return '<circle cx="' . $this->num($item['x'] ?? 0) . '" cy="' . $this->num($item['y'] ?? 0) . '"'
            . ' r="' . $this->num($radius) . '" fill="' . $color . '"' . $this->opacity($item) . '/>'
            . '<text x="' . $this->num($item['x'] ?? 0) . '" y="' . $this->num($item['y'] ?? 0) . '"'
            . ' fill="' . $this->readableInk($color) . '"'
            . ' font-family="' . $this->escape(self::FONT_STACK) . '"'
            . ' font-size="' . $this->num($radius * 1.2) . '" font-weight="700"'
            . ' text-anchor="middle" dominant-baseline="central"' . $this->opacity($item) . '>'
            . $this->escape((string) (int) ($item['n'] ?? 0)) . '</text>';
    }

    private function readableInk(string $color): string
    {
        $red = (int) hexdec(substr($color, 1, 2));
        $green = (int) hexdec(substr($color, 3, 2));
        $blue = (int) hexdec(substr($color, 5, 2));

        return ($red * 299 + $green * 587 + $blue * 114) / 1000 > 150 ? '#111827' : '#ffffff';
    }

    /**
     * Kein automatischer Zeilenumbruch: Die Zeilen stehen so im Modell, wie
     * der Nutzer sie eingegeben hat (siehe Abschnitt 3.3).
     *
     * @param array<string, mixed> $item
     */
    private function text(array $item): string
    {
        $text = (string) ($item['text'] ?? '');
        if ($text === '') {
            return '';
        }
        $size = (float) ($item['s'] ?? 16);
        $x = (float) ($item['x'] ?? 0);
        $y = (float) ($item['y'] ?? 0);
        $padding = $size * 0.25;

        $markup = '';
        $fill = $item['f'] ?? null;
        $boxWidth = $item['bw'] ?? null;
        $boxHeight = $item['bh'] ?? null;
        if ($fill !== null && (is_int($boxWidth) || is_float($boxWidth))
            && (is_int($boxHeight) || is_float($boxHeight))) {
            $markup .= '<rect x="' . $this->num($x - $padding) . '" y="' . $this->num($y - $padding) . '"'
                . ' width="' . $this->num((float) $boxWidth + $padding * 2) . '"'
                . ' height="' . $this->num((float) $boxHeight + $padding * 2) . '"'
                . ' rx="' . $this->num($padding) . '" fill="' . $this->color($fill) . '"'
                . $this->opacity($item) . '/>';
        }

        $tspans = '';
        foreach (explode("\n", $text) as $index => $line) {
            $lineY = $y + $size + $index * $size * 1.25;
            $tspans .= '<tspan x="' . $this->num($x) . '" y="' . $this->num($lineY) . '">'
                . $this->escape($line) . '</tspan>';
        }

        return $markup . '<text fill="' . $this->color($item['c'] ?? null) . '"'
            . ' font-family="' . $this->escape(self::FONT_STACK) . '"'
            . ' font-size="' . $this->num($size) . '"' . $this->opacity($item) . '>'
            . $tspans . '</text>';
    }
}
