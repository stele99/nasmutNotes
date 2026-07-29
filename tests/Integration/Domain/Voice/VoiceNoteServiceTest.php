<?php

declare(strict_types=1);

namespace Tests\Integration\Domain\Voice;

use App\Domain\Import\MarkdownConverter;
use App\Domain\NotebookService;
use App\Domain\Notes\NoteService;
use App\Domain\Notes\ProseMirrorValidator;
use App\Domain\PageService;
use App\Domain\User;
use App\Domain\Voice\OpenAiClient;
use App\Domain\Voice\VoiceNoteService;
use App\Domain\Voice\VoiceServiceException;
use App\Repositories\AuditLogRepository;
use App\Repositories\NoteAttachmentRepository;
use App\Repositories\NotebookRepository;
use App\Repositories\NoteContentRepository;
use App\Repositories\NoteVersionRepository;
use App\Repositories\PageRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\ShareRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\ValidationException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\Support\InMemoryDatabaseTrait;

final class VoiceNoteServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PDO $pdo;
    private PageService $pages;
    private NoteService $notes;
    private NotebookService $notebooks;
    private SettingsRepository $settings;
    private MockHandler $httpMock;
    private User $user;
    private string $tmpPath;
    private string $lastRequestBody = '';

    protected function setUp(): void
    {
        $this->pdo = $this->makeDatabase();
        $workspaces = new WorkspaceRepository($this->pdo);
        $pageRepository = new PageRepository($this->pdo);
        $notebookRepository = new NotebookRepository($this->pdo);
        $this->notebooks = new NotebookService($this->pdo, $notebookRepository, $workspaces);
        $this->pages = new PageService(
            $pageRepository,
            $workspaces,
            new ShareRepository($this->pdo),
            $this->notebooks,
        );
        $this->notes = new NoteService(
            $this->pdo,
            $this->pages,
            $pageRepository,
            new NoteContentRepository($this->pdo),
            new NoteVersionRepository($this->pdo),
            new NoteAttachmentRepository($this->pdo),
            new ProseMirrorValidator(),
        );
        $this->settings = new SettingsRepository($this->pdo);
        $this->httpMock = new MockHandler();
        $this->tmpPath = sys_get_temp_dir() . '/voice-test-' . bin2hex(random_bytes(4));

        $this->user = $this->makeUser($workspaces, 'a@example.com');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpPath . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpPath);
    }

    public function testCreatesNoteWithGeneratedTitleAndDerivedNotebook(): void
    {
        $this->notebooks->create($this->user, ['name' => 'Garten']);
        $this->notebooks->create($this->user, ['name' => 'Arbeit']);

        $this->queueTranscription('Hochbeet aufbauen und Tomaten vorziehen');
        $this->queuePostprocessing([
            'title' => 'Gartenarbeiten im Frühjahr',
            // Groß-/Kleinschreibung darf die Zuordnung nicht verhindern.
            'notebook' => 'garten',
            'text' => "Für das Frühjahr:\n\n- Hochbeet aufbauen\n- Tomaten vorziehen",
        ]);

        $result = $this->service()->createNote($this->user, $this->makeUpload(), 'iphash');

        self::assertSame('Gartenarbeiten im Frühjahr', $result['page']['title']);
        self::assertSame('Garten', $result['page']['notebook_name']);
        self::assertSame('Garten', $result['notebook_name']);

        $content = $this->notes->get($this->user, (int) $result['page']['id']);
        self::assertSame(2, $content['version']);
        self::assertStringContainsString('Hochbeet aufbauen', json_encode($content['content'], JSON_THROW_ON_ERROR));
        self::assertSame('bulletList', $content['content']['content'][1]['type']);
    }

    public function testVoiceNoteKeepsTheOptionalRecordingLocation(): void
    {
        $this->queueTranscription('Notiz mit Ort');
        $this->queuePostprocessing(['title' => 'Unterwegs', 'notebook' => '', 'text' => 'Notiz mit Ort.']);

        $result = $this->service()->createNote($this->user, $this->makeUpload(), 'iphash', [
            'lat' => '48.775846',
            'lon' => '9.182932',
            'accuracy' => '30',
        ]);

        self::assertSame(48.775846, (float) $result['page']['location_lat']);
        self::assertSame(9.182932, (float) $result['page']['location_lon']);
        self::assertSame(30.0, (float) $result['page']['location_accuracy']);
    }

    public function testMapsADictatedLogEntryOntoTheColumnsAndTheLocalTime(): void
    {
        $columns = [
            ['id' => 7, 'name' => 'Tätigkeit', 'type' => 'text'],
            ['id' => 8, 'name' => 'Dauer', 'type' => 'hours'],
            ['id' => 9, 'name' => 'Kosten', 'type' => 'money'],
        ];

        $this->queueTranscription('Gestern beim Kunden die Heizung gewartet, zweieinhalb Stunden, 89,90 Euro Material');
        $this->queuePostprocessing([
            'occurred_at' => '2026-07-28T14:30',
            'values' => [
                // Groß-/Kleinschreibung des Spaltennamens darf nichts ausmachen.
                'tätigkeit' => 'Heizung gewartet',
                'Dauer' => '2.5',
                'Kosten' => '89.90',
                'Unbekannt' => 'wird verworfen',
            ],
        ]);

        $result = $this->service()->transcribeForLog(
            $this->makeUpload(),
            $columns,
            '2026-07-29T09:40:00+02:00',
        );

        self::assertSame([7 => 'Heizung gewartet', 8 => '2.5', 9 => '89.90'], $result['values']);
        // Ohne Zeitzone im Modellwert gilt die des Clients.
        self::assertSame('2026-07-28T14:30:00+02:00', $result['occurred_at']);
        self::assertStringContainsString('Heizung', $result['transcript']);
    }

    public function testLocationColumnsAreKeptAwayFromTheModel(): void
    {
        $columns = [
            ['id' => 3, 'name' => 'Tätigkeit', 'type' => 'text'],
            ['id' => 4, 'name' => 'Einsatzort', 'type' => 'location'],
        ];

        $this->queueTranscription('In Stuttgart beim Kunden gewesen');
        // Selbst wenn das Modell eine Ortsspalte zurückgibt, zählt sie nicht:
        // Der Ort kommt bei einer Aufnahme vom Gerät (FR-LOG-11).
        $this->queuePostprocessing([
            'occurred_at' => '2026-07-29T09:00',
            'values' => ['Tätigkeit' => 'Kundenbesuch', 'Einsatzort' => 'Stuttgart'],
        ]);

        $result = $this->service()->transcribeForLog(
            $this->makeUpload(),
            $columns,
            '2026-07-29T09:40:00+02:00',
        );

        self::assertSame([3 => 'Kundenbesuch'], $result['values']);
        // Die Ortsspalte steht dem Modell auch gar nicht erst zur Verfügung.
        $request = $this->lastChatRequest();
        self::assertStringContainsString('Tätigkeit', $request);
        self::assertStringNotContainsString('Einsatzort', $request);
    }

    public function testUserColumnsAreKeptAwayFromTheModel(): void
    {
        $columns = [
            ['id' => 3, 'name' => 'Tätigkeit', 'type' => 'text'],
            ['id' => 4, 'name' => 'Erstellt von', 'type' => 'user'],
        ];

        $this->queueTranscription('Kundenbesuch dokumentiert');
        $this->queuePostprocessing([
            'occurred_at' => '2026-07-29T09:00',
            'values' => ['Tätigkeit' => 'Kundenbesuch', 'Erstellt von' => 'Andere Person'],
        ]);

        $result = $this->service()->transcribeForLog(
            $this->makeUpload(),
            $columns,
            '2026-07-29T09:40:00+02:00',
        );

        self::assertSame([3 => 'Kundenbesuch'], $result['values']);
        self::assertStringNotContainsString('Erstellt von', $this->lastChatRequest());
    }

    public function testUnknownNotebookLeavesNoteUnassigned(): void
    {
        $this->notebooks->create($this->user, ['name' => 'Arbeit']);

        $this->queueTranscription('Irgendetwas ganz anderes');
        $this->queuePostprocessing([
            'title' => 'Neue Idee',
            // Erfundene Namen dürfen kein Notizbuch anlegen.
            'notebook' => 'Ideen',
            'text' => 'Irgendetwas ganz anderes.',
        ]);

        $result = $this->service()->createNote($this->user, $this->makeUpload(), 'iphash');

        self::assertNull($result['page']['notebook_id']);
        self::assertNull($result['notebook_name']);
        self::assertCount(1, $this->notebooks->list($this->user));
    }

    public function testWithoutPostprocessingTheRawTranscriptBecomesTheNote(): void
    {
        $this->settings->set(VoiceNoteService::POSTPROCESS_ENABLED_KEY, '0');
        $this->queueTranscription('Erster Satz. Zweiter Satz.');

        $result = $this->service()->createNote($this->user, $this->makeUpload(), 'iphash');

        self::assertSame('Erster Satz', $result['page']['title']);
        self::assertSame('Erster Satz. Zweiter Satz.', $result['transcript']);
        // Nur ein Aufruf: das Nachbearbeitungsmodell bleibt ungenutzt.
        self::assertSame(0, $this->httpMock->count());
    }

    public function testEmptyTranscriptIsRejectedBeforeAnythingIsStored(): void
    {
        $this->queueTranscription('   ');

        try {
            $this->service()->createNote($this->user, $this->makeUpload(), 'iphash');
            self::fail('Eine leere Aufnahme darf keine Notiz anlegen.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('keine Sprache', $e->getMessage());
        }

        self::assertSame([], $this->pages->list($this->user, 'updated', null, false));
    }

    public function testAnUnstorableTextLeavesNoEmptyPageBehind(): void
    {
        $this->queueTranscription('Text');
        // Über der Größengrenze des Notizinhalts (1 MB als JSON).
        $this->queuePostprocessing([
            'title' => 'Zu groß',
            'notebook' => '',
            'text' => str_repeat("Kurze Zeile mit ein wenig Text.\n\n", 34_000),
        ]);

        try {
            $this->service()->createNote($this->user, $this->makeUpload(), 'iphash');
            self::fail('Ein abgelehnter Inhalt darf keine Seite hinterlassen.');
        } catch (ValidationException) {
        }

        self::assertSame([], $this->pages->list($this->user, 'updated', null, false));
    }

    public function testServiceErrorsSurfaceAsVoiceServiceException(): void
    {
        $this->httpMock->append(new GuzzleResponse(401, [
            'Content-Type' => 'application/json',
        ], (string) json_encode(['error' => ['message' => 'Incorrect API key provided']])));

        $this->expectException(VoiceServiceException::class);
        $this->service()->createNote($this->user, $this->makeUpload(), 'iphash');
    }

    public function testDisabledFeatureIsRefused(): void
    {
        $this->settings->set(VoiceNoteService::ENABLED_KEY, '0');

        $this->expectException(ValidationException::class);
        $this->service()->transcribe($this->user, $this->makeUpload());
    }

    public function testMissingApiKeyIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->service(apiKey: '')->transcribe($this->user, $this->makeUpload());
    }

    public function testUnsupportedFormatIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->transcribe($this->user, $this->makeUpload(mediaType: 'application/pdf', name: 'brief.pdf'));
    }

    public function testRecordingAboveTheConfiguredLimitIsRefused(): void
    {
        $this->settings->set(VoiceNoteService::MAX_MB_KEY, '1');

        $this->expectException(ValidationException::class);
        $this->service()->transcribe($this->user, $this->makeUpload(bytes: 2 * 1024 * 1024));
    }

    public function testAdminSettingsAreValidatedAndPersisted(): void
    {
        $service = $this->service();
        $settings = $service->updateSettings($this->user, [
            'enabled' => true,
            'transcribe_model' => 'gpt-4o-transcribe',
            'language' => 'EN',
            'postprocess_model' => 'gpt-4o',
            'max_seconds' => 120,
            'max_mb' => 10,
            'postprocess_prompt' => '',
        ], 'iphash');

        self::assertTrue($settings->enabled);
        self::assertSame('gpt-4o-transcribe', $settings->transcribeModel);
        self::assertSame('en', $settings->language);
        self::assertSame(120, $settings->maxSeconds);
        self::assertSame(10, $settings->maxMb);
        // Eine geleerte Anweisung fällt auf die Standardfassung zurück.
        self::assertSame(VoiceNoteService::DEFAULT_PROMPT, $settings->postprocessPrompt);

        $this->expectException(ValidationException::class);
        $service->updateSettings($this->user, ['transcribe_model' => 'modell mit leerzeichen'], 'iphash');
    }

    public function testApiKeyIsNeverExposedToTheAdminView(): void
    {
        $view = $this->service(apiKey: 'sk-test-secret-1234')->settings()->toAdminArray();

        self::assertTrue($view['has_api_key']);
        self::assertSame('…1234', $view['api_key_hint']);
        self::assertArrayNotHasKey('api_key', $view);
        self::assertStringNotContainsString('secret', (string) json_encode($view));
    }

    /** Nutzernachricht der letzten Chat-Anfrage - was das Modell zu sehen bekam. */
    private function lastChatRequest(): string
    {
        $payload = json_decode($this->lastRequestBody, true);

        return is_array($payload) ? (string) ($payload['messages'][1]['content'] ?? '') : '';
    }

    private function service(string $apiKey = 'sk-test'): VoiceNoteService
    {
        $stack = HandlerStack::create($this->httpMock);
        $stack->push(Middleware::mapRequest(function (RequestInterface $request): RequestInterface {
            $this->lastRequestBody = (string) $request->getBody();

            return $request;
        }));
        $http = new Client(['handler' => $stack, 'http_errors' => false]);

        return new VoiceNoteService(
            $this->settings,
            new OpenAiClient($http),
            $this->pages,
            $this->notes,
            $this->notebooks,
            new MarkdownConverter(),
            new AuditLogRepository($this->pdo),
            $this->tmpPath,
            $apiKey,
        );
    }

    private function queueTranscription(string $text): void
    {
        $this->httpMock->append(new GuzzleResponse(200, [
            'Content-Type' => 'application/json',
        ], (string) json_encode(['text' => $text])));
    }

    /** @param array<string, mixed> $answer */
    private function queuePostprocessing(array $answer): void
    {
        $this->httpMock->append(new GuzzleResponse(200, [
            'Content-Type' => 'application/json',
        ], (string) json_encode([
            'choices' => [['message' => ['content' => json_encode($answer)]]],
        ])));
    }

    private function makeUpload(
        string $mediaType = 'audio/webm;codecs=opus',
        string $name = 'aufnahme.webm',
        int $bytes = 4096,
    ): UploadedFile {
        $path = sys_get_temp_dir() . '/voice-upload-' . bin2hex(random_bytes(4));
        file_put_contents($path, str_repeat('a', $bytes));

        return new UploadedFile(
            new StreamFactory()->createStreamFromFile($path),
            $name,
            $mediaType,
            $bytes,
            UPLOAD_ERR_OK,
        );
    }

    private function makeUser(WorkspaceRepository $workspaces, string $email): User
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (google_sub, email, name, created_at) VALUES (:sub, :email, :name, :now)'
        );
        $stmt->execute([
            'sub' => $email,
            'email' => $email,
            'name' => $email,
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $workspaces->createForUser($id);

        return new User($id, $email, $email, $email, null, true, false);
    }
}
