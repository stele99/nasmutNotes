<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Bündelt View + Vite + CSP-Nonce/CSRF-Token, damit Controller nicht bei
 * jedem render()-Aufruf dieselben Layout-Variablen wiederholen müssen.
 */
final class Renderer
{
    public function __construct(
        private readonly View $view,
        private readonly Vite $vite,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function page(Request $request, string $template, array $data = [], string $title = 'Notizen & Tasks'): string
    {
        return $this->view->render($template, array_merge([
            '_layout' => 'layout',
            'title' => $title,
            'vite' => $this->vite,
            'cspNonce' => $request->getAttribute('csp_nonce'),
            'csrfToken' => $request->getAttribute('csrf_token'),
        ], $data));
    }
}
