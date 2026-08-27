<?php

declare(strict_types=1);

namespace Tests\Integration\Domain\Assistant;

use App\Domain\Ai\AiModelSettings;
use App\Domain\Ai\AiUsageRecorder;
use App\Domain\Assistant\AssistantService;
use App\Domain\Assistant\UpstreamReply;
use App\Domain\User;
use App\Repositories\AiUsageRepository;
use App\Repositories\SettingsRepository;
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

final class AssistantServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PDO $pdo;
    private SettingsRepository $settings;
    private AiUsageRepository $usage;
    private MockHandler $httpMock;
    private string $lastRequestBody = '';
    private string $lastRequestUri = '';
    private string $tmpPath;
    private User $user;

    protected function setUp(): void
    {
        $this->pdo = $this->makeDatabase();
        $this->settings = new SettingsRepository($this->pdo);
        $this->usage = new AiUsageRepository($this->pdo);
        $this->httpMock = new MockHandler();
        $this->tmpPath = sys_get_temp_dir() . '/assistant-test-' . bin2hex(random_bytes(4));
        $this->user = $this->makeUser('a@example.com');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpPath . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpPath);
    }

    public function testChatOverridesModelAndKeepsAllOtherParameters(): void
    {
        $this->settings->set(AiModelSettings::DEFAULT_MODEL_KEY, 'gpt-admin-model');
        $this->settings->set(AiModelSettings::DEFAULT_REASONING_KEY, 'medium');
        $this->queueChatResponse(['choices' => [['message' => ['content' => 'Antwort']]]]);

        $reply = $this->service()->chat($this->user, [
            'model' => 'was-der-client-schickte',
            'messages' => [['role' => 'user', 'content' => 'Hallo']],
            'temperature' => 0.7,
            'reasoning_effort' => 'vom-client-gesetzt',
        ]);

        self::assertSame(200, $reply->status);

        $payload = json_decode($this->lastRequestBody, true);
        self::assertSame('gpt-admin-model', $payload['model']);
        // Modell und Reasoning-Aufwand entscheidet der Server, nicht der Client.
        self::assertSame('medium', $payload['reasoning_effort']);
        self::assertSame(0.7, $payload['temperature']);
        self::assertSame('Hallo', $payload['messages'][0]['content']);
        self::assertSame('https://api.openai.com/v1/chat/completions', $this->lastRequestUri);

        // Ohne Usage-Angabe des Anbieters wird grob geschätzt und markiert.
        $stmt = $this->pdo->prepare('SELECT estimated FROM ai_usage_log');
        $stmt->execute();
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testReasoningIsOmittedWhenNothingIsConfigured(): void
    {
        $this->settings->set(AiModelSettings::DEFAULT_MODEL_KEY, 'gpt-admin-model');
        $this->queueChatResponse(['choices' => [['message' => ['content' => 'Antwort']]]]);

        $this->service()->chat($this->user, ['messages' => []]);

        $payload = json_decode($this->lastRequestBody, true);
        self::assertArrayNotHasKey('reasoning_effort', $payload);
    }

    public function testChatRecordsUsageFromProviderResponse(): void
    {
        $this->settings->set(AiModelSettings::DEFAULT_MODEL_KEY, 'gpt-admin-model');
        $this->queueChatResponse([
            'choices' => [['message' => ['content' => 'Antwort']]],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8, 'total_tokens' => 20],
        ]);

        $reply = $this->service()->chat($this->user, ['messages' => []]);
        self::assertStringContainsString('Antwort', (string) $reply->body);

        $summary = $this->usage->summaryForUserByModel($this->user->id);
        self::assertCount(1, $summary);
        self::assertSame(20, $summary[0]['tokens_total']);
        self::assertSame('gpt-admin-model', $summary[0]['model']);
    }

    public function testChatStreamsAndTeesTheUsageChunk(): void
    {
        $this->settings->set(AiModelSettings::DEFAULT_MODEL_KEY, 'gpt-admin-model');
        $sse = "data: {\"id\":\"1\",\"choices\":[{\"delta\":{\"content\":\"Hallo\"}}]}\n\n"
            . "data: {\"id\":\"1\",\"choices\":[{\"delta\":{\"content\":\" Welt\"}}]}\n\n"
            . "data: {\"id\":\"1\",\"choices\":[],\"usage\":{\"prompt_tokens\":10,\"completion_tokens\":4,\"total_tokens\":14}}\n\n"
            . "data: [DONE]\n\n";
        $this->httpMock->append(new GuzzleResponse(200, ['Content-Type' => 'text/event-stream'], $sse));

        $reply = $this->service()->chat($this->user, ['messages' => [], 'stream' => true]);

        self::assertSame('text/event-stream', $reply->contentType);

        $payload = json_decode($this->lastRequestBody, true);
        self::assertTrue($payload['stream']);
        self::assertTrue($payload['stream_options']['include_usage']);

        // Der Tee-Stream reicht unverändert durch und bucht nach dem Ende.
        self::assertSame($sse, $this->readAll($reply));
        self::assertSame(14, $this->usage->summaryForUser($this->user->id)['tokens_total']);
    }

    public function testStreamedUsageWithoutProviderUsageIsEstimated(): void
    {
        $this->settings->set(AiModelSettings::DEFAULT_MODEL_KEY, 'gpt-admin-model');
        $sse = "data: {\"choices\":[{\"delta\":{\"content\":\"abcd abcd\"}}]}\n\n"
            . "data: [DONE]\n\n";
        $this->httpMock->append(new GuzzleResponse(200, ['Content-Type' => 'text/event-stream'], $sse));

        $reply = $this->service()->chat($this->user, ['messages' => [['role' => 'user', 'content' => 'hi']]]);
        $this->readAll($reply);

        $stmt = $this->pdo->prepare('SELECT estimated, total_tokens FROM ai_usage_log');
        $stmt->execute();
        $row = $stmt->fetch();
        self::assertSame(1, (int) $row['estimated']);
        self::assertGreaterThan(0, (int) $row['total_tokens']);
    }

    public function testProviderErrorsAreForwardedWithoutRecording(): void
    {
        $this->httpMock->append(new GuzzleResponse(401, ['Content-Type' => 'application/json'], (string) json_encode([
            'error' => ['message' => 'Incorrect API key', 'type' => 'invalid_request_error'],
        ])));

        $reply = $this->service()->chat($this->user, ['messages' => []]);

        self::assertSame(401, $reply->status);
        self::assertStringContainsString('Incorrect API key', (string) $reply->body);
        self::assertSame(0, $this->rowCount());
    }

    public function testDisabledAssistantIsRefused(): void
    {
        $this->settings->set(AssistantService::ENABLED_KEY, '0');

        $this->expectException(ValidationException::class);
        $this->service()->chat($this->user, ['messages' => []]);
    }

    public function testTranscribeUsesVoiceSettingsAndMapsUsage(): void
    {
        $this->settings->set('voice_transcribe_model', 'gpt-4o-mini-transcribe');
        $this->settings->set('voice_language', 'de');
        $this->httpMock->append(new GuzzleResponse(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'text' => 'Guten Morgen',
            'usage' => ['input_tokens' => 30, 'output_tokens' => 6, 'total_tokens' => 36],
        ])));

        $result = $this->service()->transcribe($this->user, $this->makeUpload());

        self::assertSame(200, $result['status']);
        $decoded = json_decode($result['body'], true);
        self::assertSame('Guten Morgen', $decoded['text']);
        self::assertSame('https://api.openai.com/v1/audio/transcriptions', $this->lastRequestUri);

        $summary = $this->usage->summaryForUserByModel($this->user->id);
        self::assertSame(36, $summary[0]['tokens_total']);
        self::assertSame('gpt-4o-mini-transcribe', $summary[0]['model']);
    }

    public function testTranscribeRejectsOversizedRecordings(): void
    {
        $this->settings->set('voice_max_mb', '1');

        $this->expectException(ValidationException::class);
        $this->service()->transcribe($this->user, $this->makeUpload(bytes: 2 * 1024 * 1024));
    }

    public function testExtractStreamedUsagePicksTheFinalUsageChunk(): void
    {
        $tail = "data: {\"choices\":[{\"delta\":{\"content\":\"x\"}}]}\n\n"
            . "data: {\"choices\":[],\"usage\":{\"total_tokens\":42}}\n\n"
            . "data: [DONE]\n\n";

        $usage = AssistantService::extractStreamedUsage($tail);

        self::assertNotNull($usage);
        self::assertSame(42, $usage['total_tokens']);
        self::assertNull(AssistantService::extractStreamedUsage("data: [DONE]\n\n"));
    }

    private function service(): AssistantService
    {
        $stack = HandlerStack::create($this->httpMock);
        $stack->push(Middleware::mapRequest(function (RequestInterface $request): RequestInterface {
            $this->lastRequestBody = (string) $request->getBody();
            $this->lastRequestUri = (string) $request->getUri();

            return $request;
        }));

        return new AssistantService(
            $this->settings,
            new AiUsageRecorder($this->usage),
            new Client(['handler' => $stack, 'http_errors' => false]),
            'sk-test',
            'https://api.openai.com/v1',
            'gpt-4o-mini',
            $this->tmpPath,
        );
    }

    /** @param array<string, mixed> $payload */
    private function queueChatResponse(array $payload): void
    {
        $this->httpMock->append(new GuzzleResponse(200, [
            'Content-Type' => 'application/json',
        ], (string) json_encode($payload)));
    }

    private function readAll(UpstreamReply $reply): string
    {
        $body = '';
        while (!$reply->body->eof()) {
            $body .= $reply->body->read(4096);
        }

        return $body;
    }

    private function rowCount(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ai_usage_log');
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    private function makeUpload(
        string $mediaType = 'audio/webm;codecs=opus',
        string $name = 'aufnahme.webm',
        int $bytes = 4096,
    ): UploadedFile {
        $path = sys_get_temp_dir() . '/assistant-upload-' . bin2hex(random_bytes(4));
        file_put_contents($path, str_repeat('a', $bytes));

        return new UploadedFile(
            new StreamFactory()->createStreamFromFile($path),
            $name,
            $mediaType,
            $bytes,
            UPLOAD_ERR_OK,
        );
    }

    private function makeUser(string $email): User
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (google_sub, email, name, created_at)
             VALUES (:sub, :email, :name, :now)'
        );
        $stmt->execute([
            'sub' => $email,
            'email' => $email,
            'name' => $email,
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);

        return new User((int) $this->pdo->lastInsertId(), $email, $email, $email, null, true, false);
    }
}
