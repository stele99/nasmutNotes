<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\PageService;
use App\Support\CurrentUser;
use App\Support\Renderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AppController
{
    public function __construct(
        private readonly PageService $pages,
        private readonly Renderer $renderer,
    ) {
    }

    public function shell(Request $request, Response $response): Response
    {
        $user = CurrentUser::require($request);
        $html = $this->renderer->page($request, 'app', ['isAdmin' => $user->isAdmin], 'Workspace');
        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /** @param array<string, string> $args */
    public function page(Request $request, Response $response, array $args): Response
    {
        $user = CurrentUser::require($request);
        $page = $this->pages->find($user, (int) $args['id']);

        $html = $this->renderer->page(
            $request,
            'page',
            ['isAdmin' => $user->isAdmin, 'page' => $page],
            $page['title'],
        );
        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
