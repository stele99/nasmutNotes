<?php

declare(strict_types=1);

namespace Tests\Integration\Domain\Ai;

use App\Domain\Ai\AiModelSettings;
use App\Domain\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\SettingsRepository;
use App\Support\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDatabaseTrait;

final class AiModelSettingsTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PDO $pdo;
    private SettingsRepository $settings;
    private AiModelSettings $defaults;
    private User $admin;

    protected function setUp(): void
    {
        $this->pdo = $this->makeDatabase();
        $this->settings = new SettingsRepository($this->pdo);
        $this->defaults = new AiModelSettings($this->settings, new AuditLogRepository($this->pdo), 'env-fallback-model');
        $this->admin = $this->makeUser('admin@example.com');
    }

    public function testModelFallsBackToTheEnvironmentValue(): void
    {
        self::assertSame('env-fallback-model', $this->defaults->defaultModel());

        $this->settings->set(AiModelSettings::DEFAULT_MODEL_KEY, 'gpt-5.1');
        self::assertSame('gpt-5.1', $this->defaults->defaultModel());
    }

    public function testReasoningResolutionPrefersTheAreaSetting(): void
    {
        self::assertSame('', $this->defaults->reasoningFor('note_ai_reasoning'));

        $this->settings->set(AiModelSettings::DEFAULT_REASONING_KEY, 'low');
        self::assertSame('low', $this->defaults->reasoningFor('note_ai_reasoning'));

        $this->settings->set('note_ai_reasoning', 'high');
        self::assertSame('high', $this->defaults->reasoningFor('note_ai_reasoning'));
        // Andere Bereiche bleiben beim globalen Default.
        self::assertSame('low', $this->defaults->reasoningFor('voice_quick_reasoning'));
    }

    public function testDefaultsAreValidatedAndPersisted(): void
    {
        $result = $this->defaults->setDefaults($this->admin, [
            'model' => 'gpt-5.1',
            'reasoning' => 'low',
            'base_url' => 'https://ai.example.test/v1/',
        ], 'iphash');

        self::assertSame('gpt-5.1', $result['model']);
        self::assertSame('low', $result['reasoning']);
        self::assertSame('https://ai.example.test/v1', $result['base_url']);

        // Leere Werte sind zulässig: Modell fällt auf die Umgebung, Reasoning
        // wird nicht mitgeschickt.
        $cleared = $this->defaults->setDefaults($this->admin, ['model' => '', 'reasoning' => ''], 'iphash');
        self::assertSame('env-fallback-model', $cleared['model']);
        self::assertSame('', $cleared['reasoning']);
    }

    public function testInvalidModelNameIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->defaults->setDefaults($this->admin, ['model' => 'modell mit leerzeichen'], 'iphash');
    }

    public function testInvalidReasoningLevelIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->defaults->setDefaults($this->admin, ['reasoning' => 'extrem'], 'iphash');
    }

    public function testInsecureBaseUrlIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->defaults->setDefaults($this->admin, ['base_url' => 'http://insecure.example'], 'iphash');
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
