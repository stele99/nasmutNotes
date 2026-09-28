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

    /**
     * Wie page(), aber nur Titel und <main> - für Seitenwechsel innerhalb der
     * App, wenn die Hülle schon im Dokument steht (`?_partial=main`).
     *
     * @param array<string, mixed> $data
     */
    public function fragment(Request $request, string $template, array $data = [], string $title = 'Notizen & Tasks'): string
    {
        return $this->view->render($template, array_merge([
            '_layout' => 'partial_layout',
            'title' => $title,
            'partial' => true,
            'cspNonce' => $request->getAttribute('csp_nonce'),
            'csrfToken' => $request->getAttribute('csrf_token'),
        ], $data));
    }

    /** Fragt der Client nur <main> an (pageList.js, Offline-Vorladen)? */
    public static function wantsFragment(Request $request): bool
    {
        return ($request->getQueryParams()['_partial'] ?? null) === 'main';
    }
}
