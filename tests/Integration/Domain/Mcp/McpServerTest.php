<?php

declare(strict_types=1);

namespace Tests\Integration\Domain\Mcp;

use App\Controllers\McpController;
use App\Domain\Export\MarkdownRenderer;
use App\Domain\Import\MarkdownConverter;
use App\Domain\Log\LogService;
use App\Domain\Mcp\McpServer;
use App\Domain\Mcp\McpToken;
use App\Domain\Mcp\McpTokenService;
use App\Domain\Mcp\McpTools;
use App\Domain\NotebookService;
use App\Domain\Notes\NoteService;
use App\Domain\Notes\ProseMirrorValidator;
use App\Domain\PageService;
use App\Domain\TaskBoardService;
use App\Domain\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\LogRepository;
use App\Repositories\McpTokenRepository;
use App\Repositories\NoteAttachmentRepository;
use App\Repositories\NotebookRepository;
use App\Repositories\NotebookShareRepository;
use App\Repositories\NoteContentRepository;
use App\Repositories\NoteVersionRepository;
use App\Repositories\PageRepository;
use App\Repositories\SearchRepository;
use App\Repositories\ShareRepository;
use App\Repositories\TaskRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\AdminEmails;
use App\Support\NotFoundException;
use App\Support\RateLimiter;
use App\Support\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\Support\InMemoryDatabaseTrait;

final class McpServerTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PDO $pdo;
    private PageService $pages;
    private NoteService $notes;
    private McpTokenService $tokens;
    private McpServer $server;
    private User $user;
    private User $otherUser;
    private int $nextId = 1;

    protected function setUp(): void
    {
        $this->pdo = $this->makeDatabase();
        $workspaces = new WorkspaceRepository($this->pdo);
        $pageRepository = new PageRepository($this->pdo);
        $notebookShares = new NotebookShareRepository($this->pdo);
        $notebooks = new NotebookService($this->pdo, new NotebookRepository($this->pdo), $workspaces, $notebookShares);
        $this->pages = new PageService(
            $pageRepository,
            $workspaces,
            new ShareRepository($this->pdo),
            $notebooks,
            null,
            $notebookShares,
        );
        $validator = new ProseMirrorValidator();
        $this->notes = new NoteService(
            $this->pdo,
            $this->pages,
            $pageRepository,
            new NoteContentRepository($this->pdo),
            new NoteVersionRepository($this->pdo),
            new NoteAttachmentRepository($this->pdo),
            $validator,
        );
        $categories = new CategoryRepository($this->pdo);
        $tasks = new TaskRepository($this->pdo);
        $auditLog = new AuditLogRepository($this->pdo);
        $this->tokens = new McpTokenService(
            new McpTokenRepository($this->pdo),
            new UserRepository($this->pdo, new AdminEmails('')),
            $this->pages,
            $auditLog,
        );
        $this->server = new McpServer(new McpTools(
            $this->pages,
            $notebooks,
            $this->notes,
            new TaskBoardService($this->pdo, $this->pages, $categories, $tasks, $pageRepository),
            new LogService($this->pdo, $this->pages, $pageRepository, new LogRepository($this->pdo)),
            new SearchRepository($this->pdo),
            $tasks,
            $categories,
            new MarkdownRenderer(includeAnnotationTexts: false),
            new MarkdownConverter(),
            $validator,
            $this->tokens,
            $auditLog,
        ));

        $this->user = $this->makeUser($workspaces, 'a@example.com');
        $this->otherUser = $this->makeUser($workspaces, 'b@example.com');
    }

    private function makeUser(WorkspaceRepository $workspaces, string $email): User
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (google_sub, email, name, created_at) VALUES (:sub, :email, :name, :now)'
        );
        $stmt->execute(['sub' => $email, 'email' => $email, 'name' => $email, 'now' => gmdate('Y-m-d\TH:i:s.v\Z')]);
        $id = (int) $this->pdo->lastInsertId();
        $workspaces->createForUser($id);

        return new User($id, $email, $email, $email, null, true, false);
    }

    private function token(string $mode = McpToken::WRITE_NONE, bool $canCreate = false, ?User $user = null): McpToken
    {
        $issued = $this->tokens->issue($user ?? $this->user, 'Client', $mode, $canCreate, 'ip');
        $resolved = $this->tokens->resolve((string) $issued['token']);
        self::assertNotNull($resolved);

        return $resolved['token'];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int|string, mixed>
     */
    private function rpc(McpToken $token, string $method, array $params = [], ?User $user = null): array
    {
        $response = $this->server->handle(
            ['jsonrpc' => '2.0', 'id' => $this->nextId++, 'method' => $method, 'params' => $params],
            $token,
            $user ?? $this->user,
            'ip',
        );
        self::assertIsArray($response);

        return $response;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(McpToken $token, string $tool, array $arguments, ?User $user = null): array
    {
        $response = $this->rpc($token, 'tools/call', ['name' => $tool, 'arguments' => $arguments], $user);
        self::assertArrayHasKey('result', $response, (string) json_encode($response));

        return $response['result'];
    }

    /** @param array<string, mixed> $result */
    private static function assertToolError(array $result, string $needle): void
    {
        self::assertTrue($result['isError'] ?? false, (string) json_encode($result));
        self::assertStringContainsString($needle, (string) $result['content'][0]['text']);
    }

    private function note(string $title, string $text, ?User $user = null): int
    {
        $user ??= $this->user;
        $pageId = (int) $this->pages->create($user, 'note', $title, null)['id'];
        $this->notes->save($user, $pageId, [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]],
        ], 1);

        return $pageId;
    }

    private function pageCount(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM pages');
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    private function versionCount(int $pageId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM note_versions WHERE page_id = :page_id');
        $stmt->execute(['page_id' => $pageId]);

        return (int) $stmt->fetchColumn();
    }

    public function testInitializeNegotiatesProtocolVersion(): void
    {
        $token = $this->token();

        $known = $this->rpc($token, 'initialize', ['protocolVersion' => '2025-03-26']);
        self::assertSame('2025-03-26', $known['result']['protocolVersion']);
        self::assertSame(['listChanged' => false], $known['result']['capabilities']['tools']);
        self::assertSame('nasmut-notes', $known['result']['serverInfo']['name']);

        $unknown = $this->rpc($token, 'initialize', ['protocolVersion' => '1999-01-01']);
        self::assertSame(McpServer::SUPPORTED_VERSIONS[0], $unknown['result']['protocolVersion']);
    }

    public function testNotificationsAreNotAnswered(): void
    {
        self::assertNull($this->server->handle(
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            $this->token(),
            $this->user,
            'ip',
        ));
    }

    public function testUnknownMethodAndToolAreProtocolErrors(): void
    {
        $token = $this->token();

        self::assertSame(-32601, $this->rpc($token, 'resources/list')['error']['code']);
        self::assertSame(
            -32602,
            $this->rpc($token, 'tools/call', ['name' => 'drop_database', 'arguments' => []])['error']['code'],
        );
    }

    public function testToolListMatchesTokenPermissions(): void
    {
        $names = static fn (array $response): array => array_column($response['result']['tools'], 'name');

        $readOnly = $names($this->rpc($this->token(), 'tools/list'));
        self::assertSame(['list_notebooks', 'list_pages', 'search_pages', 'read_page'], $readOnly);

        $createOnly = $names($this->rpc($this->token(McpToken::WRITE_NONE, true), 'tools/list'));
        self::assertContains('create_note', $createOnly);
        self::assertNotContains('update_note', $createOnly);

        $full = $names($this->rpc($this->token(McpToken::WRITE_ALL, true), 'tools/list'));
        self::assertContains('update_note', $full);
        self::assertContains('add_task', $full);
    }

    public function testReadPageReturnsMarkdownAndVersion(): void
    {
        $pageId = $this->note('Einkauf', 'Milch und Brot');

        $result = $this->call($this->token(), 'read_page', ['page_id' => $pageId]);

        self::assertFalse($result['isError'] ?? false);
        self::assertSame('Einkauf', $result['structuredContent']['page']['title']);
        self::assertSame("Milch und Brot\n", $result['structuredContent']['markdown']);
        self::assertSame(2, $result['structuredContent']['version']);
        self::assertFalse($result['structuredContent']['page']['mcp_can_write']);
    }

    public function testForeignPagesStayInvisible(): void
    {
        $foreign = $this->note('Geheim', 'nur für B', $this->otherUser);

        self::assertToolError($this->call($this->token(), 'read_page', ['page_id' => $foreign]), 'nicht gefunden');

        $search = $this->call($this->token(), 'search_pages', ['query' => 'nur für']);
        self::assertSame([], $search['structuredContent']['pages']);
    }

    public function testEncryptedNotesAreNotReadable(): void
    {
        $pageId = $this->note('Tresor', 'geheim');
        $this->pdo->exec("UPDATE pages SET is_encrypted = 1 WHERE id = {$pageId}");

        self::assertToolError($this->call($this->token(), 'read_page', ['page_id' => $pageId]), 'verschlüsselt');
    }

    public function testReadOnlyTokenCannotWrite(): void
    {
        $pageId = $this->note('Notiz', 'alt');

        $result = $this->call($this->token(), 'update_note', ['page_id' => $pageId, 'markdown' => 'neu']);

        self::assertToolError($result, 'nur lesen');
        self::assertStringContainsString('alt', $this->notes->get($this->user, $pageId)['content']['content'][0]['content'][0]['text']);
    }

    public function testSelectedModeNeedsPageGrant(): void
    {
        $pageId = $this->note('Notiz', 'Anfang');
        $token = $this->token(McpToken::WRITE_SELECTED);

        $denied = $this->call($token, 'update_note', ['page_id' => $pageId, 'markdown' => 'Ende']);
        self::assertToolError($denied, 'nicht für MCP-Clients freigegeben');

        $access = $this->tokens->setPageWritable($this->user, $pageId, true, 'ip');
        self::assertTrue($access['writable']);
        self::assertSame(1, $access['selected_tokens']);

        $allowed = $this->call($token, 'update_note', ['page_id' => $pageId, 'markdown' => 'Ende']);
        self::assertFalse($allowed['isError'] ?? false, (string) json_encode($allowed));

        $read = $this->call($token, 'read_page', ['page_id' => $pageId]);
        self::assertSame("Anfang\n\nEnde\n", $read['structuredContent']['markdown']);
        self::assertTrue($read['structuredContent']['page']['mcp_can_write']);
    }

    public function testGrantIsPerUser(): void
    {
        $pageId = $this->note('Notiz', 'Anfang');
        $this->tokens->setPageWritable($this->user, $pageId, true, 'ip');

        self::assertFalse($this->tokens->isPageGranted($this->otherUser, $pageId));
        $this->expectException(NotFoundException::class);
        $this->tokens->setPageWritable($this->otherUser, $pageId, true, 'ip');
    }

    public function testReplaceKeepsSnapshotAndChecksVersion(): void
    {
        $pageId = $this->note('Notiz', 'Erste Fassung');
        $token = $this->token(McpToken::WRITE_ALL);
        $before = $this->versionCount($pageId);

        $stale = $this->call($token, 'update_note', [
            'page_id' => $pageId,
            'markdown' => '# Neu',
            'mode' => 'replace',
            'expected_version' => 1,
        ]);
        self::assertToolError($stale, 'aktuelle Version 2');

        $replaced = $this->call($token, 'update_note', [
            'page_id' => $pageId,
            'markdown' => "# Neu\n\n- [ ] Punkt",
            'mode' => 'replace',
            'expected_version' => 2,
            'title' => 'Umbenannt',
        ]);
        self::assertFalse($replaced['isError'] ?? false, (string) json_encode($replaced));
        self::assertSame(3, $replaced['structuredContent']['version']);
        self::assertSame('Umbenannt', $replaced['structuredContent']['title']);
        self::assertSame($before + 1, $this->versionCount($pageId));

        $content = $this->notes->get($this->user, $pageId)['content'];
        self::assertSame('heading', $content['content'][0]['type']);
        self::assertSame('taskList', $content['content'][1]['type']);
    }

    public function testReplaceKeepsExistingImageAttributes(): void
    {
        $pageId = (int) $this->pages->create($this->user, 'note', 'Bild', null)['id'];
        $imageToken = str_repeat('a', 64);
        $this->pdo->prepare(
            "INSERT INTO note_attachments (page_id, token_hash, storage_name, mime_type, byte_size, width, height)
             VALUES (:page_id, :hash, 'x.png', 'image/png', 10, 40, 30)"
        )->execute(['page_id' => $pageId, 'hash' => hash('sha256', $imageToken)]);
        $annotations = ['v' => 1, 'space' => ['w' => 1000, 'h' => 800], 'items' => [[
            'id' => 'abcd1234',
            't' => 'line',
            'c' => '#e11d48',
            'w' => 4,
            'x1' => 10,
            'y1' => 20,
            'x2' => 30,
            'y2' => 40,
            'head' => 'end',
        ]]];
        $image = ['type' => 'image', 'attrs' => [
            'src' => "/api/attachments/{$imageToken}",
            'alt' => 'Plan',
            'width' => 40,
            'height' => 30,
            'annotations' => $annotations,
        ]];
        $this->notes->save($this->user, $pageId, ['type' => 'doc', 'content' => [$image]], 1);
        $token = $this->token(McpToken::WRITE_ALL);

        $read = $this->call($token, 'read_page', ['page_id' => $pageId]);
        $markdown = (string) $read['structuredContent']['markdown'];
        self::assertStringContainsString("/api/attachments/{$imageToken}", $markdown);
        self::assertStringNotContainsString('Bildnotizen', $markdown);

        $result = $this->call($token, 'update_note', [
            'page_id' => $pageId,
            'markdown' => "Vorwort\n\n" . $markdown,
            'mode' => 'replace',
        ]);
        self::assertFalse($result['isError'] ?? false, (string) json_encode($result));

        $content = $this->notes->get($this->user, $pageId)['content'];
        $saved = $content['content'][1];
        self::assertSame('image', $saved['type']);
        self::assertSame($annotations, $saved['attrs']['annotations']);
        self::assertSame(40, $saved['attrs']['width']);
    }

    public function testCreateNoteNeedsPermissionAndGrantsCreatedPage(): void
    {
        $denied = $this->call($this->token(McpToken::WRITE_SELECTED), 'create_note', ['title' => 'X']);
        self::assertToolError($denied, 'keine neuen Notizen');

        $token = $this->token(McpToken::WRITE_SELECTED, true);
        $created = $this->call($token, 'create_note', ['title' => 'Protokoll', 'markdown' => "## Punkte\n\n- eins"]);
        self::assertFalse($created['isError'] ?? false, (string) json_encode($created));
        $pageId = (int) $created['structuredContent']['page']['id'];
        self::assertTrue($created['structuredContent']['page']['mcp_can_write']);

        $appended = $this->call($token, 'update_note', ['page_id' => $pageId, 'markdown' => '- zwei']);
        self::assertFalse($appended['isError'] ?? false, (string) json_encode($appended));
        self::assertSame('Protokoll', $this->pages->find($this->user, $pageId)['title']);
    }

    public function testInvalidTitleDoesNotCreatePage(): void
    {
        $token = $this->token(McpToken::WRITE_NONE, true);
        $countBefore = $this->pageCount();

        $result = $this->call($token, 'create_note', ['title' => '', 'markdown' => 'Text']);

        self::assertTrue($result['isError'] ?? false);
        self::assertSame($countBefore, $this->pageCount());
    }

    public function testTasksCanBeAddedAndCompleted(): void
    {
        $pageId = (int) $this->pages->create($this->user, 'task', 'Board', null)['id'];
        $token = $this->token(McpToken::WRITE_ALL);

        $added = $this->call($token, 'add_task', ['page_id' => $pageId, 'title' => 'Steuer machen']);
        self::assertFalse($added['isError'] ?? false, (string) json_encode($added));
        $taskId = (int) $added['structuredContent']['task']['id'];

        $duplicate = $this->call($token, 'add_task', ['page_id' => $pageId, 'title' => 'steuer machen']);
        self::assertToolError($duplicate, "Aufgabe #{$taskId}");

        $done = $this->call($token, 'update_task', ['task_id' => $taskId, 'is_done' => true]);
        self::assertTrue($done['structuredContent']['task']['is_done']);

        $read = $this->call($token, 'read_page', ['page_id' => $pageId]);
        self::assertTrue($read['structuredContent']['categories'][0]['tasks'][0]['is_done']);
    }

    public function testForeignTaskIsNotFound(): void
    {
        $pageId = (int) $this->pages->create($this->otherUser, 'task', 'Fremd', null)['id'];
        $foreign = $this->call($this->token(McpToken::WRITE_ALL, false, $this->otherUser), 'add_task', [
            'page_id' => $pageId,
            'title' => 'B',
        ], $this->otherUser);
        $taskId = (int) $foreign['structuredContent']['task']['id'];

        $result = $this->call($this->token(McpToken::WRITE_ALL), 'update_task', ['task_id' => $taskId, 'is_done' => true]);

        self::assertToolError($result, "Aufgabe #{$taskId} nicht gefunden");
    }

    public function testLogEntryByColumnName(): void
    {
        $pageId = (int) $this->pages->create($this->user, 'log', 'Tagebuch', null)['id'];
        $token = $this->token(McpToken::WRITE_ALL);

        $added = $this->call($token, 'add_log_entry', [
            'page_id' => $pageId,
            'values' => ['eintrag' => 'Ölwechsel'],
        ]);
        self::assertFalse($added['isError'] ?? false, (string) json_encode($added));

        $read = $this->call($token, 'read_page', ['page_id' => $pageId]);
        self::assertSame(['Eintrag' => 'Ölwechsel'], $read['structuredContent']['entries'][0]['values']);

        $unknown = $this->call($token, 'add_log_entry', ['page_id' => $pageId, 'values' => ['Gibtsnicht' => 1]]);
        self::assertToolError($unknown, 'Unbekannte Spalte');
    }

    public function testTokenLifecycle(): void
    {
        $issued = $this->tokens->issue($this->user, 'Claude', McpToken::WRITE_NONE, false, 'ip');
        $raw = (string) $issued['token'];
        self::assertStringStartsWith(McpTokenService::TOKEN_PREFIX, $raw);
        self::assertArrayNotHasKey('token', $this->tokens->listFor($this->user)[0]);

        $updated = $this->tokens->update($this->user, (int) $issued['id'], ['write_mode' => 'all', 'can_create' => true], 'ip');
        self::assertSame('all', $updated['write_mode']);
        self::assertTrue($updated['can_create']);
        $resolved = $this->tokens->resolve($raw);
        self::assertNotNull($resolved);
        self::assertSame(McpToken::WRITE_ALL, $resolved['token']->writeMode);

        // Ein Automations-Token ohne Präfix wird gar nicht erst nachgeschlagen.
        self::assertNull($this->tokens->resolve(substr($raw, strlen(McpTokenService::TOKEN_PREFIX))));

        $this->tokens->revoke($this->user, (int) $issued['id'], 'ip');
        self::assertNull($this->tokens->resolve($raw));
    }

    public function testForeignTokenCannotBeChanged(): void
    {
        $issued = $this->tokens->issue($this->user, 'Claude', McpToken::WRITE_NONE, false, 'ip');

        $this->expectException(NotFoundException::class);
        $this->tokens->update($this->otherUser, (int) $issued['id'], ['write_mode' => 'all'], 'ip');
    }

    public function testInvalidWriteModeIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->tokens->issue($this->user, 'Claude', 'admin', false, 'ip');
    }

    private function post(string $body, ?string $authorization): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/mcp')
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream($body));
        if ($authorization !== null) {
            $request = $request->withHeader('Authorization', $authorization);
        }
        $controller = new McpController($this->tokens, $this->server, new RateLimiter($this->pdo, true));

        return $controller->handle($request, (new ResponseFactory())->createResponse());
    }

    public function testEndpointRejectsMissingOrUnknownToken(): void
    {
        $body = '{"jsonrpc":"2.0","id":1,"method":"ping"}';

        $missing = $this->post($body, null);
        self::assertSame(401, $missing->getStatusCode());
        self::assertStringStartsWith('Bearer', $missing->getHeaderLine('WWW-Authenticate'));

        self::assertSame(401, $this->post($body, 'Bearer nmcp_' . str_repeat('0', 64))->getStatusCode());
    }

    public function testEndpointAnswersRequestsAndAcceptsNotifications(): void
    {
        $raw = (string) $this->tokens->issue($this->user, 'Client', McpToken::WRITE_NONE, false, 'ip')['token'];

        $ping = $this->post('{"jsonrpc":"2.0","id":"a","method":"ping"}', "Bearer {$raw}");
        self::assertSame(200, $ping->getStatusCode());
        self::assertSame('{"jsonrpc":"2.0","id":"a","result":{}}', (string) $ping->getBody());

        $notification = $this->post('{"jsonrpc":"2.0","method":"notifications/initialized"}', "Bearer {$raw}");
        self::assertSame(202, $notification->getStatusCode());
        self::assertSame('', (string) $notification->getBody());

        $broken = $this->post('{kein json', "Bearer {$raw}");
        self::assertSame(400, $broken->getStatusCode());
        self::assertStringContainsString('-32700', (string) $broken->getBody());

        $list = (string) $this->post('{"jsonrpc":"2.0","id":2,"method":"tools/list"}', "Bearer {$raw}")->getBody();
        // Leere Eigenschaften müssen als JSON-Objekt ankommen, nicht als Liste.
        self::assertStringContainsString('"inputSchema":{"type":"object","properties":{}}', $list);
    }
}
