<?php

declare(strict_types=1);

use App\Controllers\Admin\AdminDashboardController;
use App\Controllers\Admin\BackupAdminController;
use App\Controllers\Admin\InviteAdminController;
use App\Controllers\AppController;
use App\Controllers\AttachmentController;
use App\Controllers\AuthController;
use App\Controllers\BoardController;
use App\Controllers\CategoryController;
use App\Controllers\DeviceTokenController;
use App\Controllers\ExportController;
use App\Controllers\GeocodeController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\ImportController;
use App\Controllers\InviteController;
use App\Controllers\LogController;
use App\Controllers\MapTileController;
use App\Controllers\NoteAiController;
use App\Controllers\NotebookController;
use App\Controllers\NoteController;
use App\Controllers\PageAttachmentController;
use App\Controllers\PageController;
use App\Controllers\ProfileController;
use App\Controllers\PublicShareController;
use App\Controllers\SearchController;
use App\Controllers\ShareController;
use App\Controllers\TaskController;
use App\Controllers\UserInviteController;
use App\Controllers\VoiceNoteController;
use App\Middleware\DeviceTokenAuthMiddleware;
use App\Middleware\RequireAdminMiddleware;
use App\Middleware\RequireAuthMiddleware;
use Psr\Container\ContainerInterface;
use Slim\App;

return static function (App $app, ContainerInterface $container): void {
    $app->get('/health', HealthController::class);
    $app->get('/', HomeController::class);

    $app->get('/auth/google', [AuthController::class, 'google']);
    $app->get('/auth/callback', [AuthController::class, 'callback']);
    $app->post('/auth/logout', [AuthController::class, 'logout']);

    $app->get('/invite/{token}', [InviteController::class, 'accept']);
    $app->get('/s/{token}', [PublicShareController::class, 'open']);
    $app->get('/s/{token}/images/{imageToken}', [PublicShareController::class, 'image']);
    $app->get('/s/{token}/files/{attachmentId}', [PublicShareController::class, 'file']);
    $app->post('/s/{token}/copy', [PublicShareController::class, 'copy'])->add(new RequireAuthMiddleware(true));

    $app->get('/admin', [AdminDashboardController::class, 'page'])
        ->add(new RequireAdminMiddleware(false))
        ->add(new RequireAuthMiddleware(false));

    $app->get('/admin/ai', [AdminDashboardController::class, 'aiPage'])
        ->add(new RequireAdminMiddleware(false))
        ->add(new RequireAuthMiddleware(false));

    $app->get('/admin/invites', [InviteAdminController::class, 'page'])
        ->add(new RequireAdminMiddleware(false))
        ->add(new RequireAuthMiddleware(false));

    $app->get('/admin/backups', [BackupAdminController::class, 'page'])
        ->add(new RequireAdminMiddleware(false))
        ->add(new RequireAuthMiddleware(false));

    $app->group('/api/admin', function ($group): void {
        $group->get('/invites', [InviteAdminController::class, 'index']);
        $group->post('/invites', [InviteAdminController::class, 'store']);
        $group->delete('/invites/{id}', [InviteAdminController::class, 'destroy']);
        $group->get('/overview', [AdminDashboardController::class, 'overview']);
        $group->delete('/users/{id}', [AdminDashboardController::class, 'destroyUser']);
        $group->patch('/users/{id}/quota', [AdminDashboardController::class, 'updateUserQuota']);
        $group->post('/users/{id}/compress-images', [AdminDashboardController::class, 'compressUserImages']);
        $group->patch('/settings/default-quota', [AdminDashboardController::class, 'updateDefaultQuota']);
        $group->patch('/settings/max-attachment', [AdminDashboardController::class, 'updateMaxAttachment']);
        $group->patch(
            '/settings/offline-attachment',
            [AdminDashboardController::class, 'updateOfflineAttachmentLimit'],
        );
        $group->patch('/settings/voice', [AdminDashboardController::class, 'updateVoiceSettings']);
        $group->patch('/settings/note-ai', [AdminDashboardController::class, 'updateNoteAiSettings']);
        $group->post('/attachments/purge-orphans', [AdminDashboardController::class, 'purgeOrphans']);
        $group->get('/backups', [BackupAdminController::class, 'index']);
        $group->post('/backups', [BackupAdminController::class, 'store']);
        $group->get('/backups/{id}/download', [BackupAdminController::class, 'download']);
        $group->delete('/backups/{id}', [BackupAdminController::class, 'destroy']);
    })
        ->add(new RequireAdminMiddleware(true))
        ->add(new RequireAuthMiddleware(true));

    $app->group('/api/invites', function ($group): void {
        $group->get('', [UserInviteController::class, 'index']);
        $group->post('', [UserInviteController::class, 'store']);
        $group->delete('/{id}', [UserInviteController::class, 'destroy']);
    })->add(new RequireAuthMiddleware(true));

    $app->get('/api/session', [AppController::class, 'session'])->add(new RequireAuthMiddleware(true));
    $app->patch('/api/profile', [ProfileController::class, 'update'])->add(new RequireAuthMiddleware(true));

    // Automations-Token für NotesVoice (FR-NVOICE) - selbst verwaltet, sichtbar
    // und widerrufbar nur die eigenen.
    $app->group('/api/profile/device-tokens', function ($group): void {
        $group->get('', [DeviceTokenController::class, 'index']);
        $group->post('', [DeviceTokenController::class, 'store']);
        $group->delete('/{id}', [DeviceTokenController::class, 'destroy']);
    })->add(new RequireAuthMiddleware(true));

    $app->get('/app', [AppController::class, 'shell'])->add(new RequireAuthMiddleware(false));
    $app->get('/app/page/{id}', [AppController::class, 'page'])->add(new RequireAuthMiddleware(false));

    $app->group('/api/notebooks', function ($group): void {
        $group->get('', [NotebookController::class, 'index']);
        $group->post('', [NotebookController::class, 'store']);
        $group->patch('/{id}', [NotebookController::class, 'update']);
        $group->delete('/{id}', [NotebookController::class, 'destroy']);
    })->add(new RequireAuthMiddleware(true));

    $app->group('/api/pages', function ($group): void {
        $group->get('', [PageController::class, 'index']);
        // Vor /{id} registriert, damit "trash" nicht als Seiten-ID gelesen wird.
        $group->delete('/trash', [PageController::class, 'emptyTrash']);
        $group->post('/trash', [PageController::class, 'trashMany']);
        $group->post('/move', [PageController::class, 'moveMany']);
        $group->post('', [PageController::class, 'store']);
        $group->patch('/{id}', [PageController::class, 'update']);
        $group->delete('/{id}', [PageController::class, 'destroy']);
        $group->post('/{id}/restore', [PageController::class, 'restore']);
        $group->delete('/{id}/purge', [PageController::class, 'purge']);
        $group->get('/{id}/shares', [ShareController::class, 'index']);
        $group->post('/{id}/shares', [ShareController::class, 'store']);
        $group->delete('/{id}/shares', [ShareController::class, 'stop']);
        $group->get('/{id}/writers', [ShareController::class, 'writers']);
        $group->get('/{id}/collaborators', [ShareController::class, 'collaborators']);
        $group->delete('/{id}/share-access', [ShareController::class, 'leave']);
        $group->get('/{id}/content', [NoteController::class, 'show']);
        $group->put('/{id}/content', [NoteController::class, 'update']);
        $group->put('/{id}/content/encryption', [NoteController::class, 'updateEncryption']);
        $group->post('/{id}/ai/rewrite', [NoteAiController::class, 'rewrite']);
        $group->get('/{id}/versions', [NoteController::class, 'versions']);
        $group->get('/{id}/versions/{vid}', [NoteController::class, 'showVersion']);
        $group->post('/{id}/versions/{vid}/restore', [NoteController::class, 'restoreVersion']);
        $group->post('/{id}/attachments/compress', [AttachmentController::class, 'compress']);
        $group->post('/{id}/attachments', [AttachmentController::class, 'store']);
        $group->get('/{id}/files', [PageAttachmentController::class, 'index']);
        $group->post('/{id}/files', [PageAttachmentController::class, 'store']);
        $group->get('/{id}/board', [BoardController::class, 'show']);
        $group->post('/{id}/categories', [BoardController::class, 'createCategory']);
        $group->get('/{id}/log', [LogController::class, 'show']);
        $group->get('/{id}/log/export', [LogController::class, 'export']);
        $group->post('/{id}/log/columns', [LogController::class, 'storeColumn']);
        $group->post('/{id}/log/entries', [LogController::class, 'storeEntry']);
        $group->post('/{id}/log/voice', [LogController::class, 'storeVoiceEntry']);
    })->add(new RequireAuthMiddleware(true));

    $app->group('/api/categories', function ($group): void {
        $group->patch('/{id}', [CategoryController::class, 'update']);
        $group->delete('/{id}', [CategoryController::class, 'destroy']);
        $group->post('/{id}/tasks/import', [TaskController::class, 'import']);
        $group->post('/{id}/tasks/voice', [TaskController::class, 'voice']);
        $group->post('/{id}/tasks', [TaskController::class, 'store']);
    })->add(new RequireAuthMiddleware(true));

    $app->group('/api/log-columns', function ($group): void {
        $group->patch('/{id}', [LogController::class, 'updateColumn']);
        $group->delete('/{id}', [LogController::class, 'destroyColumn']);
    })->add(new RequireAuthMiddleware(true));

    $app->group('/api/log-entries', function ($group): void {
        $group->patch('/{id}', [LogController::class, 'updateEntry']);
        $group->delete('/{id}', [LogController::class, 'destroyEntry']);
    })->add(new RequireAuthMiddleware(true));

    $app->group('/api/tasks', function ($group): void {
        $group->patch('/{id}', [TaskController::class, 'update']);
        $group->delete('/{id}', [TaskController::class, 'destroy']);
        $group->post('/{id}/move', [TaskController::class, 'move']);
        $group->post('/{id}/duplicate', [TaskController::class, 'duplicate']);
    })->add(new RequireAuthMiddleware(true));

    $app->delete('/api/shares/{id}', [ShareController::class, 'destroy'])
        ->add(new RequireAuthMiddleware(true));

    $app->get('/api/attachments/{token}', [AttachmentController::class, 'show'])
        ->add(new RequireAuthMiddleware(true));

    $app->post('/api/images/compress', [AttachmentController::class, 'compressAll'])
        ->add(new RequireAuthMiddleware(true));

    $app->group('/api/page-attachments', function ($group): void {
        $group->get('/{id}', [PageAttachmentController::class, 'show']);
        $group->delete('/{id}', [PageAttachmentController::class, 'destroy']);
    })->add(new RequireAuthMiddleware(true));

    $app->group('/api/import', function ($group): void {
        $group->post('/archive', [ImportController::class, 'store']);
        // Geteilter Upload: Vor /{id} registriert, damit "parts" nicht als
        // Upload-Kennung gelesen wird.
        $group->post('/archive/parts', [ImportController::class, 'begin']);
        $group->post('/archive/parts/{id}', [ImportController::class, 'append']);
        $group->post('/archive/parts/{id}/complete', [ImportController::class, 'complete']);
        $group->delete('/archive/parts/{id}', [ImportController::class, 'abort']);
    })->add(new RequireAuthMiddleware(true));

    $app->group('/api/export', function ($group): void {
        $group->get('/notebooks', [ExportController::class, 'notebooks']);
        $group->get('/workspace', [ExportController::class, 'workspace']);
    })->add(new RequireAuthMiddleware(true));

    $app->group('/api/voice', function ($group): void {
        $group->get('/config', [VoiceNoteController::class, 'config']);
        $group->post('/transcribe', [VoiceNoteController::class, 'transcribe']);
        $group->post('/notes', [VoiceNoteController::class, 'store']);
    })->add(new RequireAuthMiddleware(true));

    // NotesVoice (FR-NVOICE): außer per Session-Cookie auch per
    // Automations-Token nutzbar (Rückseitentipp-Kurzbefehl ohne Browser).
    // DeviceTokenAuthMiddleware wird zuletzt hinzugefügt, damit sie vor
    // RequireAuthMiddleware läuft (Slim führt Middleware LIFO aus) und das
    // user-Attribut bereits gesetzt ist, wenn diese es prüft.
    $deviceTokenAuth = $container->get(DeviceTokenAuthMiddleware::class);
    assert($deviceTokenAuth instanceof DeviceTokenAuthMiddleware);
    $app->post('/api/voice/quick', [VoiceNoteController::class, 'quick'])
        ->add(new RequireAuthMiddleware(true))
        ->add($deviceTokenAuth);

    $app->get('/api/search', [SearchController::class, 'index'])->add(new RequireAuthMiddleware(true));
    $app->get('/api/search/nearby', [SearchController::class, 'nearby'])->add(new RequireAuthMiddleware(true));
    $app->get('/api/geocode/search', [GeocodeController::class, 'search'])->add(new RequireAuthMiddleware(true));

    // Kartenkacheln zur Standortauswahl - server-seitig geholt (FR-NOTE-27).
    $app->get('/api/map-tiles/{z}/{x}/{y}', [MapTileController::class, 'show'])
        ->add(new RequireAuthMiddleware(true));
};
