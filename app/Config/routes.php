<?php

declare(strict_types=1);

use App\Controllers\Admin\InviteAdminController;
use App\Controllers\AppController;
use App\Controllers\AuthController;
use App\Controllers\BoardController;
use App\Controllers\CategoryController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\InviteController;
use App\Controllers\NoteController;
use App\Controllers\PageController;
use App\Controllers\SearchController;
use App\Controllers\ShareController;
use App\Controllers\TaskController;
use App\Middleware\RequireAdminMiddleware;
use App\Middleware\RequireAuthMiddleware;
use Slim\App;

return static function (App $app): void {
    $app->get('/health', HealthController::class);
    $app->get('/', HomeController::class);

    $app->get('/auth/google', [AuthController::class, 'google']);
    $app->get('/auth/callback', [AuthController::class, 'callback']);
    $app->post('/auth/logout', [AuthController::class, 'logout']);

    $app->get('/invite/{token}', [InviteController::class, 'accept']);
    $app->get('/s/{token}', [ShareController::class, 'open'])->add(new RequireAuthMiddleware(false));

    $app->get('/admin', [InviteAdminController::class, 'page'])
        ->add(new RequireAdminMiddleware(false))
        ->add(new RequireAuthMiddleware(false));

    $app->group('/api/admin', function ($group): void {
        $group->get('/invites', [InviteAdminController::class, 'index']);
        $group->post('/invites', [InviteAdminController::class, 'store']);
        $group->delete('/invites/{id}', [InviteAdminController::class, 'destroy']);
    })
        ->add(new RequireAdminMiddleware(true))
        ->add(new RequireAuthMiddleware(true));

    $app->get('/app', [AppController::class, 'shell'])->add(new RequireAuthMiddleware(false));
    $app->get('/app/page/{id}', [AppController::class, 'page'])->add(new RequireAuthMiddleware(false));

    $app->group('/api/pages', function ($group): void {
        $group->get('', [PageController::class, 'index']);
        $group->post('', [PageController::class, 'store']);
        $group->patch('/{id}', [PageController::class, 'update']);
        $group->delete('/{id}', [PageController::class, 'destroy']);
        $group->post('/{id}/restore', [PageController::class, 'restore']);
        $group->delete('/{id}/purge', [PageController::class, 'purge']);
        $group->get('/{id}/shares', [ShareController::class, 'index']);
        $group->post('/{id}/shares', [ShareController::class, 'store']);
        $group->delete('/{id}/share-access', [ShareController::class, 'leave']);
        $group->get('/{id}/content', [NoteController::class, 'show']);
        $group->put('/{id}/content', [NoteController::class, 'update']);
        $group->get('/{id}/board', [BoardController::class, 'show']);
        $group->post('/{id}/categories', [BoardController::class, 'createCategory']);
    })->add(new RequireAuthMiddleware(true));

    $app->group('/api/categories', function ($group): void {
        $group->patch('/{id}', [CategoryController::class, 'update']);
        $group->delete('/{id}', [CategoryController::class, 'destroy']);
        $group->post('/{id}/tasks/import', [TaskController::class, 'import']);
        $group->post('/{id}/tasks', [TaskController::class, 'store']);
    })->add(new RequireAuthMiddleware(true));

    $app->group('/api/tasks', function ($group): void {
        $group->patch('/{id}', [TaskController::class, 'update']);
        $group->delete('/{id}', [TaskController::class, 'destroy']);
        $group->post('/{id}/move', [TaskController::class, 'move']);
        $group->post('/{id}/duplicate', [TaskController::class, 'duplicate']);
    })->add(new RequireAuthMiddleware(true));

    $app->delete('/api/shares/{id}', [ShareController::class, 'destroy'])
        ->add(new RequireAuthMiddleware(true));

    $app->get('/api/search', [SearchController::class, 'index'])->add(new RequireAuthMiddleware(true));
};
