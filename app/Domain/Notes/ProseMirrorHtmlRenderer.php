<?php

declare(strict_types=1);

namespace App\Domain\Notes;

final class ProseMirrorHtmlRenderer
{
    /** @param array<string, mixed> $document */
    public function render(array $document, string $shareToken): string
    {
        return $this->children($document, $shareToken);
    }

    /** @param array<string, mixed> $node */
    private function node(array $node, string $shareToken): string
    {
        $type = $node['type'] ?? '';
        $content = $this->children($node, $shareToken);

        return match ($type) {
            'doc' => $content,
            'paragraph' => '<p>' . ($content !== '' ? $content : '<br>') . '</p>',
            'heading' => $this->heading($node, $content),
            'text' => $this->text($node),
            'bulletList' => '<ul>' . $content . '</ul>',
            'orderedList' => '<ol>' . $content . '</ol>',
            'listItem' => '<li>' . $content . '</li>',
            'taskList' => '<ul class="public-task-list">' . $content . '</ul>',
            'taskItem' => '<li class="public-task-item"><span aria-hidden="true">'
                . (!empty($node['attrs']['checked']) ? '☑' : '☐') . '</span><div>' . $content . '</div></li>',
            'codeBlock' => '<pre><code>' . $content . '</code></pre>',
            'blockquote' => '<blockquote>' . $content . '</blockquote>',
            'horizontalRule' => '<hr>',
            'hardBreak' => '<br>',
            'table' => '<div class="public-table-wrap"><table><tbody>' . $content . '</tbody></table></div>',
            'tableRow' => '<tr>' . $content . '</tr>',
            'tableCell' => '<td>' . $content . '</td>',
            'tableHeader' => '<th>' . $content . '</th>',
            'image' => $this->image($node, $shareToken),
            default => '',
        };
    }

    /** @param array<string, mixed> $node */
    private function children(array $node, string $shareToken): string
    {
        $html = '';
        foreach ((array) ($node['content'] ?? []) as $child) {
            if (is_array($child)) {
                $html .= $this->node($child, $shareToken);
            }
        }

        return $html;
    }

    /** @param array<string, mixed> $node */
    private function heading(array $node, string $content): string
    {
        $level = max(1, min(3, (int) ($node['attrs']['level'] ?? 2)));

        return "<h{$level}>{$content}</h{$level}>";
    }

    /** @param array<string, mixed> $node */
    private function text(array $node): string
    {
        $text = htmlspecialchars((string) ($node['text'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        foreach ((array) ($node['marks'] ?? []) as $mark) {
            if (!is_array($mark)) {
                continue;
            }
            $text = match ($mark['type'] ?? '') {
                'bold' => '<strong>' . $text . '</strong>',
                'italic' => '<em>' . $text . '</em>',
                'underline' => '<u>' . $text . '</u>',
                'strike' => '<s>' . $text . '</s>',
                'code' => '<code>' . $text . '</code>',
                'link' => $this->link($mark, $text),
                default => $text,
            };
        }

        return $text;
    }

    /** @param array<string, mixed> $mark */
    private function link(array $mark, string $text): string
    {
        $href = htmlspecialchars((string) ($mark['attrs']['href'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $text . '</a>';
    }

    /** @param array<string, mixed> $node */
    private function image(array $node, string $shareToken): string
    {
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        $src = (string) ($attrs['src'] ?? '');
        if (preg_match('#^/api/attachments/([a-f0-9]{64})$#', $src, $matches) !== 1) {
            return '';
        }
        $url = '/s/' . rawurlencode($shareToken) . '/images/' . $matches[1];
        $alt = htmlspecialchars((string) ($attrs['alt'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $width = isset($attrs['width']) ? ' width="' . max(1, (int) $attrs['width']) . '"' : '';
        $height = isset($attrs['height']) ? ' height="' . max(1, (int) $attrs['height']) . '"' : '';

        return '<img src="' . $url . '" alt="' . $alt . '"' . $width . $height . ' loading="lazy">';
    }
}
