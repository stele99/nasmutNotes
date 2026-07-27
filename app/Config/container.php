<?php

declare(strict_types=1);

use App\Controllers\HealthController;
use App\Domain\AdminService;
use App\Domain\Auth\AuthService;
use App\Domain\Auth\GoogleIdTokenVerifier;
use App\Domain\Auth\IdTokenVerifierInterface;
use App\Domain\Import\ArchiveChunkStore;
use App\Domain\Import\MarkdownConverter;
use App\Domain\Import\ZipImportService;
use App\Domain\NotebookService;
use App\Domain\Notes\AttachmentService;
use App\Domain\Notes\ImageCompressionService;
use App\Domain\Notes\NoteService;
use App\Domain\Notes\PageAttachmentService;
use App\Domain\Notes\ProseMirrorValidator;
use App\Domain\PageCopyService;
use App\Domain\PageService;
use App\Domain\SessionService;
use App\Repositories\AdminRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\InviteRepository;
use App\Repositories\NoteAttachmentRepository;
use App\Repositories\NotebookRepository;
use App\Repositories\NoteContentRepository;
use App\Repositories\PageAttachmentRepository;
use App\Repositories\PageRepository;
use App\Repositories\SearchRepository;
use App\Repositories\SessionRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\ShareRepository;
use App\Repositories\TaskRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\AdminEmails;
use App\Support\Database;
use App\Support\Env;
use App\Support\RateLimiter;
use App\Support\Renderer;
use App\Support\UploadStorage;
use App\Support\View;
use App\Support\Vite;
use DI\ContainerBuilder;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

return static function (string $rootPath): DI\Container {
    $builder = new ContainerBuilder();

    $builder->addDefinitions([
        'root_path' => $rootPath,

        PDO::class => static function (): PDO {
            $dbPath = Env::get('DB_PATH', 'var/data/app.sqlite') ?? 'var/data/app.sqlite';
            if ($dbPath !== ':memory:' && !str_starts_with($dbPath, '/')) {
                $dbPath = dirname(__DIR__, 2) . '/' . $dbPath;
            }

            return Database::connect($dbPath);
        },

        LoggerInterface::class => static function () use ($rootPath): LoggerInterface {
            $logger = new Logger('app');
            $level = match (strtolower(Env::get('LOG_LEVEL', 'info') ?? 'info')) {
                'debug' => Level::Debug,
                'warning' => Level::Warning,
                'error' => Level::Error,
                default => Level::Info,
            };

            $handler = new StreamHandler($rootPath . '/var/log/app.log', $level);
            $handler->setFormatter(new JsonFormatter());
            $logger->pushHandler($handler);

            return $logger;
        },

        AdminEmails::class => static fn (): AdminEmails => new AdminEmails(Env::get('ADMIN_EMAILS', '') ?? ''),

        UserRepository::class => static fn (PDO $pdo, AdminEmails $adminEmails): UserRepository
            => new UserRepository($pdo, $adminEmails),

        SessionRepository::class => static fn (PDO $pdo): SessionRepository => new SessionRepository($pdo),

        SearchRepository::class => static fn (PDO $pdo): SearchRepository => new SearchRepository($pdo),

        NoteAttachmentRepository::class => static fn (PDO $pdo): NoteAttachmentRepository
            => new NoteAttachmentRepository($pdo),

        SessionService::class => static fn (SessionRepository $sessions, UserRepository $users): SessionService
            => new SessionService($sessions, $users, Env::int('SESSION_LIFETIME_DAYS', 30)),

        View::class => static fn (): View => new View($rootPath . '/resources/views'),

        Vite::class => static fn (): Vite => new Vite(
            $rootPath . '/public',
            Env::bool('APP_DEBUG', false) && Env::get('APP_ENV') === 'development',
            Env::get('VITE_DEV_SERVER', 'http://localhost:5173') ?? 'http://localhost:5173',
        ),

        Renderer::class => static fn (View $view, Vite $vite): Renderer => new Renderer($view, $vite),

        UploadStorage::class => static fn (): UploadStorage => new UploadStorage(
            $rootPath,
            Env::get('UPLOAD_PATH', 'var/uploads') ?? 'var/uploads',
        ),

        SettingsRepository::class => static fn (PDO $pdo): SettingsRepository => new SettingsRepository($pdo),

        AdminRepository::class => static fn (PDO $pdo): AdminRepository => new AdminRepository($pdo),

        AttachmentService::class => static fn (
            PageService $pages,
            NoteAttachmentRepository $attachments,
            UploadStorage $storage,
            SettingsRepository $settings,
            PageAttachmentRepository $files,
        ): AttachmentService => new AttachmentService(
            $pages,
            $attachments,
            $storage,
            Env::int('MAX_UPLOAD_MB', 10),
            $settings,
            Env::int('DEFAULT_STORAGE_QUOTA_MB', 0),
            $files,
        ),

        PageAttachmentRepository::class => static fn (PDO $pdo): PageAttachmentRepository
            => new PageAttachmentRepository($pdo),

        PageAttachmentService::class => static fn (
            PageService $pages,
            PageAttachmentRepository $attachments,
            NoteAttachmentRepository $images,
            UploadStorage $storage,
            SettingsRepository $settings,
        ): PageAttachmentService => new PageAttachmentService(
            $pages,
            $attachments,
            $images,
            $storage,
            $settings,
            Env::int('MAX_ATTACHMENT_MB', 10),
            Env::int('DEFAULT_STORAGE_QUOTA_MB', 0),
            Env::int('OFFLINE_ATTACHMENT_MAX_KB', PageAttachmentService::DEFAULT_OFFLINE_MAX_KB),
        ),

        AdminService::class => static fn (
            PDO $pdo,
            AdminRepository $admin,
            AuditLogRepository $auditLog,
            UploadStorage $storage,
            ProseMirrorValidator $validator,
            SettingsRepository $settings,
            ImageCompressionService $imageCompression,
        ): AdminService => new AdminService(
            $pdo,
            $admin,
            $auditLog,
            $storage,
            $validator,
            $settings,
            $imageCompression,
            Env::int('DEFAULT_STORAGE_QUOTA_MB', 0),
            Env::int('MAX_ATTACHMENT_MB', 10),
            Env::int('OFFLINE_ATTACHMENT_MAX_KB', PageAttachmentService::DEFAULT_OFFLINE_MAX_KB),
        ),

        MarkdownConverter::class => static fn (): MarkdownConverter => new MarkdownConverter(),

        // Teile eines laufenden Uploads liegen außerhalb des Web-Roots und sind
        // flüchtig; var/ ist dafür der richtige Ort.
        ArchiveChunkStore::class => static fn (): ArchiveChunkStore => new ArchiveChunkStore(
            $rootPath . '/' . trim(Env::get('IMPORT_TMP_PATH', 'var/tmp/import') ?? 'var/tmp/import', '/'),
        ),

        ZipImportService::class => static fn (
            PageService $pages,
            NoteService $notes,
            AttachmentService $images,
            PageAttachmentService $files,
            PageRepository $pageRepository,
            NotebookService $notebooks,
            MarkdownConverter $converter,
            AuditLogRepository $auditLog,
        ): ZipImportService => new ZipImportService(
            $pages,
            $notes,
            $images,
            $files,
            $pageRepository,
            $notebooks,
            $converter,
            $auditLog,
            Env::int('IMPORT_MAX_ARCHIVE_MB', 500),
        ),

        HealthController::class => static fn (PDO $pdo, UploadStorage $storage): HealthController
            => new HealthController($pdo, $storage),

        WorkspaceRepository::class => static fn (PDO $pdo): WorkspaceRepository => new WorkspaceRepository($pdo),

        PageRepository::class => static fn (PDO $pdo): PageRepository => new PageRepository($pdo),

        NotebookRepository::class => static fn (PDO $pdo): NotebookRepository => new NotebookRepository($pdo),

        ShareRepository::class => static fn (PDO $pdo): ShareRepository => new ShareRepository($pdo),

        PageService::class => static fn (
            PageRepository $pages,
            WorkspaceRepository $workspaces,
            ShareRepository $shares,
            NotebookService $notebooks,
        ): PageService => new PageService($pages, $workspaces, $shares, $notebooks),

        PageCopyService::class => static fn (
            PDO $pdo,
            PageRepository $pages,
            WorkspaceRepository $workspaces,
            NotebookRepository $notebooks,
            NoteContentRepository $noteContents,
            NoteAttachmentRepository $images,
            PageAttachmentRepository $files,
            CategoryRepository $categories,
            TaskRepository $tasks,
            SettingsRepository $settings,
            UploadStorage $storage,
        ): PageCopyService => new PageCopyService(
            $pdo,
            $pages,
            $workspaces,
            $notebooks,
            $noteContents,
            $images,
            $files,
            $categories,
            $tasks,
            $settings,
            $storage,
            Env::int('DEFAULT_STORAGE_QUOTA_MB', 0),
        ),

        NotebookService::class => static fn (
            PDO $pdo,
            NotebookRepository $notebooks,
            WorkspaceRepository $workspaces,
        ): NotebookService => new NotebookService($pdo, $notebooks, $workspaces),

        InviteRepository::class => static fn (PDO $pdo): InviteRepository => new InviteRepository($pdo),

        RateLimiter::class => static fn (PDO $pdo): RateLimiter
            => new RateLimiter($pdo, Env::bool('RATE_LIMIT_ENABLED', true)),

        IdTokenVerifierInterface::class => static fn (): IdTokenVerifierInterface => new GoogleIdTokenVerifier(
            Env::get('GOOGLE_CLIENT_ID', '') ?? '',
            Env::get('GOOGLE_HOSTED_DOMAIN') ?: null,
            $rootPath . '/var/data/google_jwks_cache.json',
        ),

        AuthService::class => static fn (
            PDO $pdo,
            UserRepository $users,
            WorkspaceRepository $workspaces,
            InviteRepository $invites,
            AdminEmails $adminEmails,
        ): AuthService => new AuthService($pdo, $users, $workspaces, $invites, $adminEmails),
    ]);

    return $builder->build();
};
