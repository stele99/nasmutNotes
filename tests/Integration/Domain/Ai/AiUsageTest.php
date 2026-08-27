<?php

declare(strict_types=1);

namespace Tests\Integration\Domain\Ai;

use App\Domain\Ai\AiCallContext;
use App\Domain\Ai\AiUsageRecorder;
use App\Domain\Ai\AiUsageService;
use App\Domain\User;
use App\Domain\Voice\OpenAiClient;
use App\Domain\Voice\VoiceSettings;
use App\Repositories\AiModelCostRepository;
use App\Repositories\AiUsageRepository;
use App\Repositories\AuditLogRepository;
use App\Support\ValidationException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDatabaseTrait;

final class AiUsageTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PDO $pdo;
    private AiUsageRepository $usage;
    private AiModelCostRepository $costs;
    private AiUsageRecorder $recorder;
    private MockHandler $httpMock;
    private User $user;

    protected function setUp(): void
    {
        $this->pdo = $this->makeDatabase();
        $this->usage = new AiUsageRepository($this->pdo);
        $this->costs = new AiModelCostRepository($this->pdo);
        $this->recorder = new AiUsageRecorder($this->usage);
        $this->httpMock = new MockHandler();
        $this->user = $this->makeUser('a@example.com');
    }

    public function testRecordStoresProviderUsage(): void
    {
        $this->recorder->record(
            new AiCallContext($this->user->id, 'desktop_chat'),
            'gpt-4o-mini',
            ['prompt_tokens' => 120, 'completion_tokens' => 80, 'total_tokens' => 200],
        );

        $summary = $this->usage->summaryForUser($this->user->id);
        self::assertSame(200, $summary['tokens_total']);
        self::assertSame(200, $summary['tokens_30d']);
        self::assertFalse($summary['priced']);
        self::assertNull($summary['cost_total']);
    }

    public function testMissingUsageIsEstimatedAndFlagged(): void
    {
        $this->recorder->record(
            new AiCallContext($this->user->id, 'note_ai'),
            'gpt-4o-mini',
            null,
            '12345678', // 8 Zeichen -> 2 Tokens geschätzt
            'abcd',     // 4 Zeichen -> 1 Token geschätzt
        );

        $stmt = $this->pdo->prepare(
            'SELECT prompt_tokens, completion_tokens, total_tokens, estimated FROM ai_usage_log'
        );
        $stmt->execute();
        $row = $stmt->fetch();

        self::assertSame(2, (int) $row['prompt_tokens']);
        self::assertSame(1, (int) $row['completion_tokens']);
        self::assertSame(3, (int) $row['total_tokens']);
        self::assertSame(1, (int) $row['estimated']);
    }

    public function testTranscriptionUsageMapsInputAndOutputTokens(): void
    {
        $this->recorder->record(
            new AiCallContext($this->user->id, 'desktop_transcribe'),
            'gpt-4o-mini-transcribe',
            ['input_tokens' => 50, 'output_tokens' => 25, 'total_tokens' => 75],
        );

        $summary = $this->usage->summaryForUser($this->user->id);
        self::assertSame(75, $summary['tokens_total']);
    }

    public function testCostsAreJoinedAtReadTime(): void
    {
        $this->costs->upsert('gpt-4o-mini', '0.15', '0.6', 'EUR');
        $this->recorder->record(
            new AiCallContext($this->user->id, 'desktop_chat'),
            'gpt-4o-mini',
            ['prompt_tokens' => 1_000_000, 'completion_tokens' => 1_000_000, 'total_tokens' => 2_000_000],
        );

        $summary = $this->usage->summaryForUser($this->user->id);
        self::assertTrue($summary['priced']);
        self::assertSame('EUR', $summary['currency']);
        self::assertEqualsWithDelta(0.75, $summary['cost_total'], 0.0001);
        self::assertEqualsWithDelta(0.75, $summary['cost_30d'], 0.0001);
    }

    public function testThirtyDayWindowIsRolling(): void
    {
        $this->recorder->record(
            new AiCallContext($this->user->id, 'voice_note'),
            'gpt-4o-mini',
            ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        );

        // Die eine Zeile liegt 31 Tage zurück.
        $stmt = $this->pdo->prepare(
            "UPDATE ai_usage_log SET created_at = strftime('%Y-%m-%dT%H:%M:%fZ', 'now', '-31 days')"
        );
        $stmt->execute();

        $summary = $this->usage->summaryForUser($this->user->id);
        self::assertSame(15, $summary['tokens_total']);
        self::assertSame(0, $summary['tokens_30d']);
    }

    public function testOpenAiClientRecordsChatUsageThroughTheChokePoint(): void
    {
        $this->httpMock->append(new GuzzleResponse(200, [
            'Content-Type' => 'application/json',
        ], (string) json_encode([
            'choices' => [['message' => ['content' => '{"text":"ok"}']]],
            'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 7, 'total_tokens' => 18],
        ])));

        $client = new OpenAiClient($this->client(), $this->recorder);
        $client->completeJson($this->settings(), 'system', 'user', null, new AiCallContext($this->user->id, 'note_ai'));

        $summary = $this->usage->summaryForUser($this->user->id);
        self::assertSame(18, $summary['tokens_total']);

        // Ohne Kontext wird nichts gebucht (Rückwärtskompatibilität).
        $this->httpMock->append(new GuzzleResponse(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'choices' => [['message' => ['content' => '{"text":"ok"}']]],
            'usage' => ['prompt_tokens' => 99, 'completion_tokens' => 99, 'total_tokens' => 198],
        ])));
        $client->completeJson($this->settings(), 'system', 'user');
        self::assertSame(18, $this->usage->summaryForUser($this->user->id)['tokens_total']);
    }

    public function testAdminOverviewAggregatesPerUserAndModel(): void
    {
        $this->costs->upsert('gpt-4o-mini', '0.15', '0.6', 'EUR');
        $this->recorder->record(
            new AiCallContext($this->user->id, 'desktop_chat'),
            'gpt-4o-mini',
            ['prompt_tokens' => 1_000_000, 'completion_tokens' => 1_000_000, 'total_tokens' => 2_000_000],
        );
        $other = $this->makeUser('b@example.com');
        $this->recorder->record(
            new AiCallContext($other->id, 'voice_note'),
            'gpt-4o-mini-transcribe',
            ['prompt_tokens' => 40, 'completion_tokens' => 10, 'total_tokens' => 50],
        );

        $service = new AiUsageService($this->usage, $this->costs, new AuditLogRepository($this->pdo));
        $overview = $service->adminOverview();

        self::assertCount(2, $overview['users']);
        self::assertSame('a@example.com', $overview['users'][0]['email']);
        self::assertSame(2_000_000, $overview['users'][0]['tokens_total']);
        self::assertEqualsWithDelta(0.75, $overview['users'][0]['cost_total'], 0.0001);
        self::assertSame(2_000_050, $overview['totals']['tokens_total']);
        self::assertNotNull($overview['totals']['cost_total']);
    }

    public function testCostEntriesAreValidatedAndPersisted(): void
    {
        $service = new AiUsageService($this->usage, $this->costs, new AuditLogRepository($this->pdo));
        $admin = $this->makeUser('admin@example.com');

        $cost = $service->setCost($admin, [
            'model' => 'gpt-4o-mini',
            'input_per_1m' => '0,15',
            'output_per_1m' => '0.6',
            'currency' => 'eur',
        ], 'iphash');

        self::assertSame(0.15, $cost['input_per_1m']);
        self::assertSame('EUR', $cost['currency']);

        try {
            $service->setCost($admin, ['model' => 'gpt-4o-mini', 'input_per_1m' => -1, 'output_per_1m' => 1], 'iphash');
            self::fail('Negativer Preis wurde akzeptiert.');
        } catch (ValidationException) {
            // erwartet
        }

        $service->removeCost($admin, 'gpt-4o-mini', 'iphash');
        self::assertSame([], $service->costs());
    }

    private function client(): Client
    {
        return new Client(['handler' => HandlerStack::create($this->httpMock), 'http_errors' => false]);
    }

    private function settings(): VoiceSettings
    {
        return new VoiceSettings(
            true,
            'sk-test',
            'https://api.openai.com/v1',
            'gpt-4o-mini-transcribe',
            'de',
            true,
            'gpt-4o-mini',
            '',
            '',
            300,
            25,
            'gpt-4o-mini',
            '',
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
