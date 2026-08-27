<?php

declare(strict_types=1);

use App\Controllers\HealthController;
use App\Domain\AdminService;
use App\Domain\Ai\AiModelSettings;
use App\Domain\Ai\AiUsageRecorder;
use App\Domain\Ai\AiUsageService;
use App\Domain\Assistant\AssistantService;
use App\Domain\Auth\AuthService;
use App\Domain\Auth\DevicePairingService;
use App\Domain\Auth\DeviceTokenService;
use App\Domain\Auth\GoogleIdTokenVerifier;
use App\Domain\Auth\IdTokenVerifierInterface;
use App\Domain\Backup\BackupLayout;
use App\Domain\Backup\BackupService;
use App\Domain\Export\MarkdownRenderer;
use App\Domain\Export\NotebookExportService;
use App\Domain\Geo\ForwardGeocoder;
use App\Domain\Geo\MapTileProxy;
use App\Domain\Geo\NearbySearchService;
use App\Domain\Geo\ReverseGeocoder;
use App\Domain\Import\ArchiveChunkStore;
use App\Domain\Import\MarkdownConverter;
use App\Domain\Import\ZipImportService;
use App\Domain\Log\LogExportService;
use App\Domain\Log\LogService;
use App\Domain\NotebookService;
use App\Domain\Notes\AttachmentService;
use App\Domain\Notes\ImageCompressionService;
use App\Domain\Notes\NoteCryptoEnvelope;
use App\Domain\Notes\NoteRewriteService;
use App\Domain\Notes\NoteService;
use App\Domain\Notes\PageAttachmentService;
use App\Domain\Notes\ProseMirrorValidator;
use App\Domain\PageCopyService;
use App\Domain\PageService;
use App\Domain\SessionService;
use App\Domain\Voice\OpenAiClient;
use App\Domain\Voice\VoiceNoteService;
use App\Repositories\AdminRepository;
use App\Repositories\AiModelCostRepository;
use App\Repositories\AiUsageRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\DevicePairRequestRepository;
use App\Repositories\DeviceTokenRepository;
use App\Repositories\InviteRepository;
use App\Repositories\LogRepository;
use App\Repositories\NoteAttachmentRepository;
use App\Repositories\NotebookRepository;
use App\Repositories\NoteContentRepository;
use App\Repositories\NoteVersionRepository;
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

        NoteService::class => static fn (
            PDO $pdo,
            PageService $pages,
            PageRepository $pageRepository,
            NoteContentRepository $contents,
            NoteVersionRepository $versions,
            NoteAttachmentRepository $attachments,
            ProseMirrorValidator $validator,
            NoteCryptoEnvelope $cryptoEnvelope,
            AuditLogRepository $auditLog,
        ): NoteService => new NoteService(
            $pdo,
            $pages,
            $pageRepository,
            $contents,
            $versions,
            $attachments,
            $validator,
            $cryptoEnvelope,
            $auditLog,
        ),

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

        // Sicherungen liegen außerhalb des Web-Roots und überstehen ein
        // Deployment, weil var/ dabei nicht überschrieben wird (NFR-OPS-06).
        BackupLayout::class => static function () use ($rootPath): BackupLayout {
            $path = Env::get('BACKUP_PATH', 'var/backups') ?? 'var/backups';

            return new BackupLayout(
                str_starts_with($path, '/') ? rtrim($path, '/') : $rootPath . '/' . trim($path, '/'),
            );
        },

        BackupService::class => static fn (
            PDO $pdo,
            AuditLogRepository $auditLog,
            BackupLayout $layout,
            UploadStorage $storage,
        ): BackupService => new BackupService(
            $pdo,
            $auditLog,
            $layout,
            $storage->basePath(),
            Env::int('BACKUP_KEEP', 14),
        ),

        MarkdownConverter::class => static fn (): MarkdownConverter => new MarkdownConverter(),

        MarkdownRenderer::class => static fn (): MarkdownRenderer => new MarkdownRenderer(),

        NotebookExportService::class => static fn (
            WorkspaceRepository $workspaces,
            NotebookRepository $notebooks,
            PageRepository $pages,
            NoteContentRepository $contents,
            NoteAttachmentRepository $images,
            PageAttachmentRepository $files,
            CategoryRepository $categories,
            TaskRepository $tasks,
            LogRepository $log,
            UploadStorage $storage,
            MarkdownRenderer $markdown,
            AuditLogRepository $auditLog,
        ): NotebookExportService => new NotebookExportService(
            $workspaces,
            $notebooks,
            $pages,
            $contents,
            $images,
            $files,
            $categories,
            $tasks,
            $log,
            $storage,
            $markdown,
            $auditLog,
            $rootPath . '/' . trim(Env::get('EXPORT_TMP_PATH', 'var/tmp/export') ?? 'var/tmp/export', '/'),
        ),

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

        // Adresssuche zum Aufnahmeort (FR-NOTE-26). Ein leerer GEOCODER_URL
        // schaltet sie ab; dann bleibt es bei den Koordinaten.
        ReverseGeocoder::class => static fn (LoggerInterface $logger): ReverseGeocoder => new ReverseGeocoder(
            $logger,
            Env::get('GEOCODER_URL', ReverseGeocoder::DEFAULT_URL) ?? ReverseGeocoder::DEFAULT_URL,
            Env::get('APP_URL', '') ?? '',
            Env::get('GEOCODER_LANGUAGE', 'de') ?? 'de',
        ),

        ForwardGeocoder::class => static fn (LoggerInterface $logger): ForwardGeocoder => new ForwardGeocoder(
            $logger,
            Env::get('GEOCODER_SEARCH_URL', ForwardGeocoder::DEFAULT_URL) ?? ForwardGeocoder::DEFAULT_URL,
            Env::get('APP_URL', '') ?? '',
            Env::get('GEOCODER_LANGUAGE', 'de') ?? 'de',
        ),

        // Kartenkacheln für die Standortauswahl - server-seitig geholt und
        // zwischengespeichert, damit der Browser des Nutzers nie direkt mit
        // dem Kartendienst spricht (FR-NOTE-27).
        MapTileProxy::class => static function (LoggerInterface $logger) use ($rootPath): MapTileProxy {
            return new MapTileProxy(
                $logger,
                $rootPath . '/' . trim(Env::get('MAP_TILE_CACHE_PATH', 'var/cache/tiles') ?? 'var/cache/tiles', '/'),
                Env::get('MAP_TILE_URL_TEMPLATE', MapTileProxy::DEFAULT_URL_TEMPLATE) ?? MapTileProxy::DEFAULT_URL_TEMPLATE,
                Env::get('APP_URL', '') ?? '',
            );
        },

        NearbySearchService::class => static fn (
            PageService $pages,
            PageRepository $pageRepository,
            LogRepository $log,
        ): NearbySearchService => new NearbySearchService($pages, $pageRepository, $log),

        PageService::class => static fn (
            PageRepository $pages,
            WorkspaceRepository $workspaces,
            ShareRepository $shares,
            NotebookService $notebooks,
            ReverseGeocoder $geocoder,
        ): PageService => new PageService($pages, $workspaces, $shares, $notebooks, $geocoder),

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
            LogRepository $log,
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
            $log,
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

        DeviceTokenRepository::class => static fn (PDO $pdo): DeviceTokenRepository
            => new DeviceTokenRepository($pdo),

        DeviceTokenService::class => static fn (
            DeviceTokenRepository $tokens,
            UserRepository $users,
            AuditLogRepository $auditLog,
        ): DeviceTokenService => new DeviceTokenService($tokens, $users, $auditLog),

        DevicePairRequestRepository::class => static fn (PDO $pdo): DevicePairRequestRepository
            => new DevicePairRequestRepository($pdo),

        DevicePairingService::class => static fn (
            DevicePairRequestRepository $requests,
            DeviceTokenService $tokens,
            UserRepository $users,
            AuditLogRepository $auditLog,
        ): DevicePairingService => new DevicePairingService($requests, $tokens, $users, $auditLog),

        LogRepository::class => static fn (PDO $pdo): LogRepository => new LogRepository($pdo),

        LogExportService::class => static fn (
            LogService $log,
            PageService $pages,
        ): LogExportService => new LogExportService($log, $pages),

        LogService::class => static fn (
            PDO $pdo,
            PageService $pages,
            PageRepository $pageRepository,
            LogRepository $log,
            ReverseGeocoder $geocoder,
        ): LogService => new LogService($pdo, $pages, $pageRepository, $log, $geocoder),

        AiModelCostRepository::class => static fn (PDO $pdo): AiModelCostRepository
            => new AiModelCostRepository($pdo),

        AiUsageRepository::class => static fn (PDO $pdo): AiUsageRepository
            => new AiUsageRepository($pdo),

        AiUsageRecorder::class => static fn (AiUsageRepository $usage): AiUsageRecorder
            => new AiUsageRecorder($usage),

        AiUsageService::class => static fn (
            AiUsageRepository $usage,
            AiModelCostRepository $costs,
            AuditLogRepository $auditLog,
        ): AiUsageService => new AiUsageService($usage, $costs, $auditLog),

        // Gemeinsame KI-Defaults: ein LLM für alle Bereiche, Reasoning
        // bereichsweise überschreibbar. Die .env liefert nur den Fallback.
        AiModelSettings::class => static fn (
            SettingsRepository $settings,
            AuditLogRepository $auditLog,
        ): AiModelSettings => new AiModelSettings(
            $settings,
            $auditLog,
            Env::get('VOICE_POSTPROCESS_MODEL', VoiceNoteService::DEFAULT_POSTPROCESS_MODEL)
                ?? VoiceNoteService::DEFAULT_POSTPROCESS_MODEL,
        ),

        // KI-Engstelle: Jeder OpenAI-Aufruf bucht seinen Verbrauch ins
        // Verbrauchsbuch, sofern der Aufruf einen Kontext mitbringt.
        OpenAiClient::class => static fn (AiUsageRecorder $recorder): OpenAiClient
            => new OpenAiClient(null, $recorder),

        // Desktop-Assistant: Proxy-Konfiguration nach dem Muster der
        // Sprachnotizen - die .env liefert nur Anfangswerte für den
        // gemeinsamen Default der KI-Funktionen.
        AssistantService::class => static fn (
            SettingsRepository $settings,
            AiUsageRecorder $recorder,
        ): AssistantService => new AssistantService(
            $settings,
            $recorder,
            null,
            Env::get('OPENAI_KEY') ?: (Env::get('OPENAI_API_KEY', '') ?? ''),
            Env::get('OPENAI_BASE_URL', VoiceNoteService::DEFAULT_BASE_URL) ?? VoiceNoteService::DEFAULT_BASE_URL,
            Env::get('VOICE_POSTPROCESS_MODEL', VoiceNoteService::DEFAULT_POSTPROCESS_MODEL)
                ?? VoiceNoteService::DEFAULT_POSTPROCESS_MODEL,
            $rootPath . '/' . trim(Env::get('VOICE_TMP_PATH', 'var/tmp/voice') ?? 'var/tmp/voice', '/'),
        ),

        // Sprachnotizen: Der .env liefert nur Anfangswerte, maßgeblich sind die
        // im Admin-Dashboard gepflegten Einstellungen (FR-VOICE-05).
        VoiceNoteService::class => static fn (
            SettingsRepository $settings,
            OpenAiClient $client,
            PageService $pages,
            NoteService $notes,
            NotebookService $notebooks,
            MarkdownConverter $markdown,
            AuditLogRepository $auditLog,
        ): VoiceNoteService => new VoiceNoteService(
            $settings,
            $client,
            $pages,
            $notes,
            $notebooks,
            $markdown,
            $auditLog,
            $rootPath . '/' . trim(Env::get('VOICE_TMP_PATH', 'var/tmp/voice') ?? 'var/tmp/voice', '/'),
            // Das Geheimnis kommt nur aus der Umgebung; OPENAI_API_KEY bleibt als
            // gebräuchlicher Zweitname zulässig.
            Env::get('OPENAI_KEY') ?: (Env::get('OPENAI_API_KEY', '') ?? ''),
            Env::get('OPENAI_BASE_URL', VoiceNoteService::DEFAULT_BASE_URL) ?? VoiceNoteService::DEFAULT_BASE_URL,
            Env::get('VOICE_TRANSCRIBE_MODEL', VoiceNoteService::DEFAULT_TRANSCRIBE_MODEL)
                ?? VoiceNoteService::DEFAULT_TRANSCRIBE_MODEL,
            Env::get('VOICE_POSTPROCESS_MODEL', VoiceNoteService::DEFAULT_POSTPROCESS_MODEL)
                ?? VoiceNoteService::DEFAULT_POSTPROCESS_MODEL,
            Env::get('VOICE_LANGUAGE', 'de') ?? 'de',
            Env::int('VOICE_MAX_SECONDS', VoiceNoteService::DEFAULT_MAX_SECONDS),
            Env::int('VOICE_MAX_MB', VoiceNoteService::MAX_UPLOAD_MB),
        ),

        NoteRewriteService::class => static fn (
            PageService $pages,
            ProseMirrorValidator $validator,
            MarkdownConverter $markdown,
            OpenAiClient $client,
            VoiceNoteService $voice,
            SettingsRepository $settings,
            AuditLogRepository $auditLog,
        ): NoteRewriteService => new NoteRewriteService(
            $pages,
            $validator,
            $markdown,
            $client,
            $voice->settings(),
            $settings,
            $auditLog,
        ),

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
