<?php

declare(strict_types=1);

namespace Tests\Integration\Domain\Notes;

use App\Domain\Import\MarkdownConverter;
use App\Domain\Notes\NoteEncryptionException;
use App\Domain\Notes\NoteRewriteService;
use App\Domain\Notes\ProseMirrorValidator;
use App\Domain\PageService;
use App\Domain\User;
use App\Domain\Voice\OpenAiClient;
use App\Domain\Voice\VoiceSettings;
use App\Repositories\AuditLogRepository;
use App\Repositories\PageRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\ShareRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\ValidationException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Tests\Support\InMemoryDatabaseTrait;

final class NoteRewriteServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PDO $pdo;
    private PageService $pages;
    private User $user;
    private int $pageId;

    protected function setUp(): void
    {
        $this->pdo = $this->makeDatabase();
        $workspaces = new WorkspaceRepository($this->pdo);
        $this->pages = new PageService(
            new PageRepository($this->pdo),
            $workspaces,
            new ShareRepository($this->pdo),
        );
        $this->user = $this->makeUser($workspaces);
        $this->pageId = (int) $this->pages->create($this->user, 'note', 'Entwurf', null)['id'];
    }

    public function testRewritesTextIntoValidatedHeadingsAndParagraphs(): void
    {
        $service = $this->service('## Überblick\n\nDas ist ein korrigierter Text.\n\n## Details\n\nNoch ein Absatz.');
        $result = $service->rewrite($this->user, $this->pageId, [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => 'das ist ein text noch ein absatz']],
            ]],
        ], NoteRewriteService::MODE_NORMAL);

        self::assertSame('heading', $result['content']['content'][0]['type']);
        self::assertSame(2, $result['content']['content'][0]['attrs']['level']);
        self::assertStringContainsString('korrigierter Text', $result['preview']);
    }

    public function testPreservesCodeImagesAndLinksExactly(): void
    {
        $service = $this->service(static function (RequestInterface $request): string {
            $payload = json_decode((string) $request->getBody(), true);
            $source = (string) ($payload['messages'][1]['content'] ?? '');
            preg_match_all('/NASMUTKEEP(?:BLOCK|INLINE)[a-f0-9]+\d{4}/', $source, $matches);
            self::assertCount(3, $matches[0]);

            return "## Überblick\n\nDer übrige Text wurde verbessert.\n\n{$matches[0][0]}\n\n{$matches[0][1]}\n\n{$matches[0][2]}";
        });

        $result = $service->rewrite($this->user, $this->pageId, [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'der übrige text']]],
                ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => 'echo "Unverändert";']]],
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'text' => 'Besuche '],
                    ['type' => 'text', 'text' => 'Beispiel', 'marks' => [[
                        'type' => 'link',
                        'attrs' => ['href' => 'https://example.com', 'target' => '_blank', 'rel' => 'noopener noreferrer nofollow'],
                    ]]],
                    ['type' => 'text', 'text' => ' heute.'],
                ]],
                ['type' => 'image', 'attrs' => ['src' => '/api/attachments/' . str_repeat('a', 64), 'alt' => 'Originalbild']],
            ],
        ], NoteRewriteService::MODE_NORMAL);

        $encoded = json_encode($result['content'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertIsString($encoded);
        self::assertStringContainsString('echo \"Unverändert\";', $encoded);
        self::assertStringContainsString('https://example.com', $encoded);
        self::assertStringContainsString('/api/attachments/' . str_repeat('a', 64), $encoded);
    }

    public function testRejectsAChangedProtectedPlaceholder(): void
    {
        $service = $this->service('Der Platzhalter fehlt.');

        $this->expectException(ValidationException::class);
        $service->rewrite($this->user, $this->pageId, [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Bitte korrigieren']]],
                ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => 'echo 1;']]],
            ],
        ], NoteRewriteService::MODE_NORMAL);
    }

    public function testAdminCanConfigureModelPromptAndActivation(): void
    {
        $service = $this->service('Unbenutzt');

        $settings = $service->updateSettings($this->user, [
            'enabled' => true,
            'model' => 'custom-rewrite-model',
            'prompt' => 'Korrigiere den Text vorsichtig.',
        ], 'iphash');

        self::assertTrue($settings['enabled']);
        self::assertTrue($settings['usable']);
        self::assertSame('custom-rewrite-model', $settings['model']);
        self::assertSame('Korrigiere den Text vorsichtig.', $settings['prompt']);

        $reset = $service->updateSettings($this->user, ['prompt' => ''], 'iphash');
        self::assertSame(NoteRewriteService::DEFAULT_PROMPT, $reset['prompt']);
    }

    public function testInvitingModeAllowsEmojisWithoutChangingFacts(): void
    {
        $service = $this->service(static function (RequestInterface $request): string {
            $payload = json_decode((string) $request->getBody(), true);
            $prompt = (string) ($payload['messages'][0]['content'] ?? '');
            self::assertStringContainsString('jeder Überschrift genau ein passendes Emoji', $prompt);
            self::assertStringContainsString('jedem normalen Absatz ein bis zwei passende Emojis', $prompt);
            self::assertStringContainsString('keine neuen Fakten', $prompt);

            return 'Herzlich willkommen! 😊';
        });

        $result = $service->rewrite($this->user, $this->pageId, [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'herzlich willkommen']]]],
        ], NoteRewriteService::MODE_INVITING);

        self::assertStringContainsString('😊', $result['preview']);
    }

    public function testEncryptedNoteIsRejectedBeforeAiProcessing(): void
    {
        $this->pdo->prepare('UPDATE pages SET is_encrypted = 1 WHERE id = :id')
            ->execute(['id' => $this->pageId]);

        try {
            $this->service('Darf nicht aufgerufen werden.')->rewrite(
                $this->user,
                $this->pageId,
                ['type' => 'doc', 'content' => []],
                NoteRewriteService::MODE_NORMAL,
            );
            self::fail('Verschlüsselte Notiz wurde an die KI übergeben.');
        } catch (NoteEncryptionException $exception) {
            self::assertSame('NOTE_ENCRYPTED', $exception->errorCode);
        }
    }

    private function service(string|callable $markdown): NoteRewriteService
    {
        $response = static fn (string $text): Response => new Response(200, [], (string) json_encode([
            'choices' => [['message' => ['content' => (string) json_encode(['text' => $text])]]],
        ]));
        $http = new MockHandler([
            is_string($markdown)
                ? $response($markdown)
                : static fn (RequestInterface $request): Response => $response($markdown($request)),
        ]);
        $settings = new VoiceSettings(
            true,
            'test-key',
            'https://api.example.test/v1',
            'transcribe',
            'de',
            true,
            'rewrite-model',
            '',
            '',
            300,
            25,
        );

        return new NoteRewriteService(
            $this->pages,
            new ProseMirrorValidator(),
            new MarkdownConverter(),
            new OpenAiClient(new Client([
                'handler' => HandlerStack::create($http),
                'http_errors' => false,
            ])),
            $settings,
            new SettingsRepository($this->pdo),
            new AuditLogRepository($this->pdo),
        );
    }

    private function makeUser(WorkspaceRepository $workspaces): User
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (google_sub, email, name, created_at) VALUES (:sub, :email, :name, :now)'
        );
        $stmt->execute([
            'sub' => 'a@example.com',
            'email' => 'a@example.com',
            'name' => 'A',
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $workspaces->createForUser($id);

        return new User($id, 'a@example.com', 'a@example.com', 'A', null, true, false);
    }
}
