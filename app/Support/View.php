<?php

declare(strict_types=1);

namespace App\Support;

final class View
{
    public function __construct(private readonly string $viewsPath)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $content = $this->renderTemplate($template, $data);

        if (!array_key_exists('_layout', $data)) {
            return $content;
        }

        $layoutData = $data;
        $layoutData['content'] = $content;
        unset($layoutData['_layout']);

        return $this->renderTemplate($data['_layout'], $layoutData);
    }

    /** @param array<string, mixed> $data */
    private function renderTemplate(string $template, array $data): string
    {
        $path = $this->viewsPath . '/' . $template . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException("View nicht gefunden: {$template}");
        }

        $render = function (string $__path, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            include $__path;

            return (string) ob_get_clean();
        };

        return $render($path, $data);
    }
}
