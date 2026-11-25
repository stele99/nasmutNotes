<?php

declare(strict_types=1);

namespace App\Domain\Mcp;

use App\Domain\Export\MarkdownRenderer;
use App\Domain\Import\MarkdownConverter;
use App\Domain\Log\LogEntryVersionConflictException;
use App\Domain\Log\LogService;
use App\Domain\NotebookService;
use App\Domain\Notes\NoteService;
use App\Domain\Notes\NoteWriteUnavailableException;
use App\Domain\Notes\ProseMirrorValidator;
use App\Domain\Notes\VersionConflictException;
use App\Domain\PageService;
use App\Domain\TaskBoardService;
use App\Domain\TaskDuplicateTitleException;
use App\Domain\TaskVersionConflictException;
use App\Domain\TaskWriteUnavailableException;
use App\Domain\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\SearchRepository;
use App\Repositories\TaskRepository;
use App\Support\ForbiddenException;
use App\Support\NotFoundException;
use App\Support\ValidationException;

/**
 * Die Werkzeuge des MCP-Servers (FR-MCP-04/05). Jedes Werkzeug arbeitet mit
 * den Rechten des Token-Eigentümers über die normalen Domain-Services - der
 * MCP-Zugang kann also nie mehr als der Nutzer selbst. Darüber liegt die
 * zweite Schranke des Tokens: Schreibmodus und "Notizen anlegen".
 */
final class McpTools
{
    private const MAX_MARKDOWN_BYTES = 400_000;
    private const MAX_DOCUMENT_BYTES = 1_000_000;
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;
    private const PAGE_TYPES = ['note', 'task', 'log'];
    private const ATTACHMENT_SRC = '#^/api/attachments/([a-f0-9]{64})$#';

    private const READ_TOOLS = ['list_notebooks', 'list_pages', 'search_pages', 'read_page'];
    private const PAGE_WRITE_TOOLS = ['update_note', 'add_task', 'update_task', 'add_log_entry'];
    private const CREATE_TOOLS = ['create_note'];

    public function __construct(
        private readonly PageService $pages,
        private readonly NotebookService $notebooks,
        private readonly NoteService $notes,
        private readonly TaskBoardService $boards,
        private readonly LogService $logs,
        private readonly SearchRepository $search,
        private readonly TaskRepository $tasks,
        private readonly CategoryRepository $categories,
        private readonly MarkdownRenderer $renderer,
        private readonly MarkdownConverter $converter,
        private readonly ProseMirrorValidator $validator,
        private readonly McpTokenService $tokens,
        private readonly AuditLogRepository $auditLog,
    ) {
    }

    /**
     * Werkzeugliste für genau diesen Token: Was er nicht darf, sieht der
     * Client gar nicht erst - das spart dem Modell Fehlversuche.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(McpToken $token): array
    {
        $tools = [];
        foreach ($this->allDefinitions() as $name => $definition) {
            if ($this->isAllowed($token, $name)) {
                $tools[] = ['name' => $name] + $definition;
            }
        }

        return $tools;
    }

    /**
     * Führt ein Werkzeug aus. Fachliche Fehler kommen als Werkzeugergebnis mit
     * `isError` zurück, damit das Modell sie lesen kann; ein unbekannter Name
     * ist dagegen ein Protokollfehler (InvalidArgumentException).
     *
     * @param array<array-key, mixed> $arguments
     * @return array<string, mixed>
     */
    public function call(McpToken $token, User $user, string $name, array $arguments, string $ipHash): array
    {
        if (!array_key_exists($name, $this->allDefinitions())) {
            throw new \InvalidArgumentException("Unbekanntes Werkzeug: {$name}");
        }

        try {
            if (!$this->isAllowed($token, $name)) {
                throw new McpToolException(in_array($name, self::CREATE_TOOLS, true)
                    ? 'Dieser MCP-Zugang darf keine neuen Notizen anlegen.'
                    : 'Dieser MCP-Zugang darf nur lesen.');
            }

            $data = match ($name) {
                'list_notebooks' => $this->listNotebooks($token, $user),
                'list_pages' => $this->listPages($token, $user, $arguments),
                'search_pages' => $this->searchPages($token, $user, $arguments),
                'read_page' => $this->readPage($token, $user, $arguments),
                'create_note' => $this->createNote($token, $user, $arguments, $ipHash),
                'update_note' => $this->updateNote($token, $user, $arguments, $ipHash),
                'add_task' => $this->addTask($token, $user, $arguments, $ipHash),
                'update_task' => $this->updateTask($token, $user, $arguments, $ipHash),
                'add_log_entry' => $this->addLogEntry($token, $user, $arguments, $ipHash),
                default => throw new \InvalidArgumentException("Unbekanntes Werkzeug: {$name}"),
            };
        } catch (McpToolException | ValidationException | NotFoundException | ForbiddenException $e) {
            return self::errorResult($e->getMessage());
        } catch (VersionConflictException $e) {
            return self::errorResult(
                "Die Notiz wurde inzwischen geändert (aktuelle Version {$e->currentVersion})."
                . ' Bitte neu lesen und die Änderung erneut anwenden.',
            );
        } catch (TaskDuplicateTitleException $e) {
            return self::errorResult(
                $e->getMessage() . ' (Aufgabe #' . (int) ($e->existingTask['id'] ?? 0) . ')',
            );
        } catch (TaskVersionConflictException | LogEntryVersionConflictException $e) {
            return self::errorResult($e->getMessage() . ' Bitte neu lesen und erneut versuchen.');
        } catch (NoteWriteUnavailableException | TaskWriteUnavailableException $e) {
            return self::errorResult($e->getMessage());
        }

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode(
                    $data,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                ) ?: '{}',
            ]],
            'structuredContent' => $data,
        ];
    }

    private function isAllowed(McpToken $token, string $name): bool
    {
        if (in_array($name, self::READ_TOOLS, true)) {
            return true;
        }
        if (in_array($name, self::CREATE_TOOLS, true)) {
            return $token->canCreate;
        }

        return in_array($name, self::PAGE_WRITE_TOOLS, true) && $token->writeMode !== McpToken::WRITE_NONE;
    }

    /** @return array<string, mixed> */
    private static function errorResult(string $message): array
    {
        return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
    }

    // ---------------------------------------------------------------------
    // Lesen

    /** @return array<string, mixed> */
    private function listNotebooks(McpToken $token, User $user): array
    {
        $granted = $this->tokens->grantedNotebookIds($user);
        $notebooks = array_map(static function (array $notebook) use ($token, $granted): array {
            $canEdit = McpTokenService::canEditNotebook($notebook);

            return [
                'id' => (int) $notebook['id'],
                'name' => (string) $notebook['name'],
                'is_owner' => (bool) ($notebook['is_owner'] ?? true),
                'owner_name' => $notebook['owner_name'] ?? null,
                'permission' => $canEdit ? 'write' : 'read',
                // Darf dieser Client Seiten darin ändern? Im Modus "selected"
                // nur bei freigegebenem Notizbuch; einzeln freigegebene Seiten
                // zeigt read_page.
                'mcp_can_write' => $canEdit && match ($token->writeMode) {
                    McpToken::WRITE_ALL => true,
                    McpToken::WRITE_SELECTED => isset($granted[(int) $notebook['id']]),
                    default => false,
                },
            ];
        }, $this->notebooks->listWithShared($user));

        return ['notebooks' => array_values($notebooks)];
    }

    /**
     * @param array<array-key, mixed> $args
     * @return array<string, mixed>
     */
    private function listPages(McpToken $token, User $user, array $args): array
    {
        $type = self::optionalString($args, 'type');
        if ($type !== null && !in_array($type, self::PAGE_TYPES, true)) {
            throw new McpToolException('Ungültiger Seitentyp. Erlaubt: note, task, log.');
        }
        $notebookId = self::optionalInt($args, 'notebook_id');
        $limit = min(self::MAX_LIMIT, max(1, self::optionalInt($args, 'limit') ?? self::DEFAULT_LIMIT));
        $offset = max(0, self::optionalInt($args, 'offset') ?? 0);

        $all = $this->pages->list($user, 'updated', $type, false, $notebookId);
        $slice = array_slice($all, $offset, $limit);

        return [
            'total' => count($all),
            'offset' => $offset,
            'pages' => $this->pageSummaries($token, $user, $slice),
        ];
    }

    /**
     * @param array<array-key, mixed> $args
     * @return array<string, mixed>
     */
    private function searchPages(McpToken $token, User $user, array $args): array
    {
        $query = trim(self::requiredString($args, 'query'));
        if ($query === '' || mb_strlen($query) > 100) {
            throw new McpToolException('Der Suchbegriff muss 1-100 Zeichen lang sein.');
        }
        $notebookId = self::optionalInt($args, 'notebook_id');
        $offset = max(0, self::optionalInt($args, 'offset') ?? 0);

        $result = $this->search->search(
            $this->pages->workspaceIdFor($user),
            $user->id,
            $query,
            $notebookId !== null ? 'notebook' : null,
            $notebookId,
            $offset,
        );

        $ids = array_values(array_map(static fn (array $page): int => (int) $page['id'], $result['pages']));
        $granted = $this->tokens->grantedPageIds($user, $ids);

        return [
            'has_more' => $result['has_more'],
            'pages' => array_values(array_map(static fn (array $page): array => [
                'id' => (int) $page['id'],
                'title' => (string) $page['title'],
                'type' => (string) $page['type'],
                'updated_at' => $page['updated_at'] ?? null,
                'is_encrypted' => (bool) ($page['is_encrypted'] ?? false),
                'is_shared' => (bool) ($page['is_shared'] ?? false),
                'mcp_page_granted' => isset($granted[(int) $page['id']]),
            ], $result['pages'])),
            'hint' => $token->writeMode === McpToken::WRITE_NONE
                ? null
                : 'Ob eine Seite beschreibbar ist, zeigt read_page im Feld mcp_can_write.',
        ];
    }

    /**
     * @param array<array-key, mixed> $args
     * @return array<string, mixed>
     */
    private function readPage(McpToken $token, User $user, array $args): array
    {
        $page = $this->pages->find($user, self::requiredInt($args, 'page_id'));
        if ($page['deleted_at'] !== null) {
            throw new McpToolException('Die Seite liegt im Papierkorb.');
        }
        $meta = $this->pageMeta($token, $user, $page);
        $pageId = (int) $page['id'];

        return match ((string) $page['type']) {
            'note' => $meta + $this->noteContent($user, $page),
            'task' => $meta + ['categories' => $this->boardContent($user, $pageId)],
            'log' => $meta + $this->logContent($user, $pageId),
            default => $meta,
        };
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function noteContent(User $user, array $page): array
    {
        if ((bool) $page['is_encrypted']) {
            throw new McpToolException(
                'Die Notiz ist Ende-zu-Ende-verschlüsselt; ihr Inhalt ist nur im Browser lesbar.',
            );
        }
        $content = $this->notes->get($user, (int) $page['id']);

        return [
            'version' => $content['version'],
            'markdown' => $this->renderer->render(
                $content['content'],
                static fn (string $token): string => '/api/attachments/' . $token,
            ),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function boardContent(User $user, int $pageId): array
    {
        return array_values(array_map(static fn (array $category): array => [
            'id' => (int) $category['id'],
            'name' => (string) $category['name'],
            'tasks' => array_values(array_map(static fn (array $task): array => [
                'id' => (int) $task['id'],
                'title' => (string) $task['title'],
                'is_done' => (int) $task['is_done'] === 1,
                'description' => $task['description'] ?? null,
                'responsible' => $task['responsible'] ?? null,
                'due_date' => $task['due_date'] ?? null,
                'link' => $task['link'] ?? null,
                'version' => (int) ($task['version'] ?? 1),
            ], (array) $category['tasks'])),
        ], $this->boards->board($user, $pageId)));
    }

    /** @return array<string, mixed> */
    private function logContent(User $user, int $pageId): array
    {
        $board = $this->logs->board($user, $pageId);
        $columns = [];
        foreach ($board['columns'] as $column) {
            $columns[(int) $column['id']] = [
                'id' => (int) $column['id'],
                'name' => (string) $column['name'],
                'type' => (string) $column['type'],
            ];
        }

        $entries = [];
        foreach ($board['entries'] as $entry) {
            $values = [];
            foreach ((array) ($entry['values'] ?? []) as $columnId => $value) {
                if (!isset($columns[(int) $columnId]) || !is_array($value)) {
                    continue;
                }
                $values[$columns[(int) $columnId]['name']] = $value['value_text'] ?? $value['value_number'] ?? null;
            }
            $entries[] = [
                'id' => (int) $entry['id'],
                'occurred_at' => $entry['occurred_at'],
                'created_by' => $entry['created_by_name'] ?? null,
                'values' => $values,
            ];
        }

        return [
            'columns' => array_values($columns),
            'entry_count' => $board['entry_count'],
            'entries' => $entries,
        ];
    }

    // ---------------------------------------------------------------------
    // Schreiben

    /**
     * @param array<array-key, mixed> $args
     * @return array<string, mixed>
     */
    private function createNote(McpToken $token, User $user, array $args, string $ipHash): array
    {
        $title = self::requiredString($args, 'title');
        $markdown = self::optionalString($args, 'markdown') ?? '';
        $notebookId = self::optionalInt($args, 'notebook_id');

        // Inhalt vor dem Anlegen prüfen: Eine Seite ohne den gewünschten
        // Inhalt wäre schlechter als ein klarer Fehler.
        $document = null;
        if (trim($markdown) !== '') {
            $document = $this->documentFromMarkdown($markdown, []);
            $this->validator->validate($document);
            $this->assertDocumentSize($document);
        }

        $page = $this->pages->create($user, 'note', $title, null, $notebookId);
        $pageId = (int) $page['id'];
        $version = 1;
        if ($document !== null) {
            $version = $this->notes->save($user, $pageId, $document, 1, mcpClient: $token->label)['version'];
        }

        // Was der Client selbst angelegt hat, darf er weiter bearbeiten -
        // auch im Modus "selected". Der Nutzer sieht und widerruft das im
        // Teilen-Dialog der Seite.
        $this->tokens->grantCreatedPage($user, $pageId);
        $this->audit($user, $token, 'mcp_page_created', $pageId, $ipHash);

        return $this->pageMeta($token, $user, $this->pages->find($user, $pageId)) + ['version' => $version];
    }

    /**
     * @param array<array-key, mixed> $args
     * @return array<string, mixed>
     */
    private function updateNote(McpToken $token, User $user, array $args, string $ipHash): array
    {
        $page = $this->writablePage($token, $user, self::requiredInt($args, 'page_id'));
        $pageId = (int) $page['id'];
        if ($page['type'] !== 'note') {
            throw new McpToolException('Die Seite ist keine Notiz.');
        }
        if ((bool) $page['is_encrypted']) {
            throw new McpToolException('Verschlüsselte Notizen können über MCP nicht bearbeitet werden.');
        }

        $markdown = self::optionalString($args, 'markdown');
        $title = self::optionalString($args, 'title');
        if ($markdown === null && $title === null) {
            throw new McpToolException('Bitte markdown und/oder title angeben.');
        }

        $result = ['page_id' => $pageId];
        if ($markdown !== null) {
            $mode = self::optionalString($args, 'mode') ?? 'append';
            if (!in_array($mode, ['append', 'replace'], true)) {
                throw new McpToolException('Ungültiger Modus. Erlaubt: append, replace.');
            }

            $current = $this->notes->get($user, $pageId);
            $expected = self::optionalInt($args, 'expected_version') ?? $current['version'];
            $images = $this->imageAttrs($current['content']);
            $document = $this->documentFromMarkdown($markdown, $images);
            if ($mode === 'append') {
                $document = [
                    'type' => 'doc',
                    'content' => [
                        ...array_values((array) ($current['content']['content'] ?? [])),
                        ...$document['content'],
                    ],
                ];
            }

            // Vor einem Ersetzen immer ein Versionsstand: So lässt sich jede
            // Änderung eines KI-Clients im Verlauf der Notiz zurücknehmen.
            $saved = $this->notes->save(
                $user,
                $pageId,
                $document,
                $expected,
                $mode === 'replace',
                mcpClient: $token->label,
            );
            $result['version'] = $saved['version'];
            $result['mode'] = $mode;
        }

        if ($title !== null) {
            $result['title'] = $this->pages->update($user, $pageId, ['title' => $title])['title'];
        }

        $this->audit($user, $token, 'mcp_note_updated', $pageId, $ipHash, [
            'mode' => $result['mode'] ?? null,
            'title' => $title !== null,
        ]);

        return $result;
    }

    /**
     * @param array<array-key, mixed> $args
     * @return array<string, mixed>
     */
    private function addTask(McpToken $token, User $user, array $args, string $ipHash): array
    {
        $page = $this->writablePage($token, $user, self::requiredInt($args, 'page_id'));
        $pageId = (int) $page['id'];
        if ($page['type'] !== 'task') {
            throw new McpToolException('Die Seite ist keine Aufgabenliste.');
        }

        $categoryId = self::optionalInt($args, 'category_id');
        if ($categoryId !== null) {
            if ($this->categories->findByIdForPage($categoryId, $pageId) === null) {
                throw new McpToolException("Kapitel #{$categoryId} gehört nicht zu dieser Seite.");
            }
        } else {
            $first = $this->categories->listForPage($pageId)[0] ?? null;
            $categoryId = $first !== null
                ? (int) $first['id']
                : (int) $this->boards->createCategory($user, $pageId, 'Aufgaben', null, null)['id'];
        }

        $task = $this->boards->createTask(
            $user,
            $categoryId,
            self::requiredString($args, 'title'),
            self::optionalString($args, 'description'),
            self::optionalString($args, 'responsible'),
            self::optionalString($args, 'link'),
            self::optionalBool($args, 'is_done') ?? false,
            false,
            self::optionalString($args, 'due_date'),
        );
        $this->audit($user, $token, 'mcp_task_created', $pageId, $ipHash, ['task_id' => (int) $task['id']]);

        return ['task' => ['id' => (int) $task['id'], 'category_id' => $categoryId, 'title' => (string) $task['title']]];
    }

    /**
     * @param array<array-key, mixed> $args
     * @return array<string, mixed>
     */
    private function updateTask(McpToken $token, User $user, array $args, string $ipHash): array
    {
        $taskId = self::requiredInt($args, 'task_id');
        $task = $this->tasks->find($taskId);
        $category = $task !== null && $task['deleted_at'] === null
            ? $this->categories->findById((int) $task['category_id'])
            : null;
        if ($category === null) {
            throw new McpToolException("Aufgabe #{$taskId} nicht gefunden.");
        }
        try {
            $page = $this->writablePage($token, $user, (int) $category['page_id']);
        } catch (NotFoundException) {
            // Die Seiten-ID einer fremden Aufgabe wird nicht verraten.
            throw new McpToolException("Aufgabe #{$taskId} nicht gefunden.");
        }

        $input = [];
        foreach (['title', 'description', 'responsible', 'due_date', 'link'] as $field) {
            if (array_key_exists($field, $args)) {
                $input[$field] = $args[$field] === null ? null : self::optionalString($args, $field);
            }
        }
        $isDone = self::optionalBool($args, 'is_done');
        if ($isDone !== null) {
            $input['is_done'] = $isDone;
        }
        if ($input === []) {
            throw new McpToolException('Keine Änderung angegeben.');
        }

        $updated = $this->boards->updateTask($user, $taskId, $input, self::optionalInt($args, 'expected_version'));
        $this->audit($user, $token, 'mcp_task_updated', (int) $page['id'], $ipHash, ['task_id' => $taskId]);

        return ['task' => [
            'id' => (int) $updated['id'],
            'title' => (string) $updated['title'],
            'is_done' => (int) $updated['is_done'] === 1,
            'version' => (int) ($updated['version'] ?? 1),
        ]];
    }

    /**
     * @param array<array-key, mixed> $args
     * @return array<string, mixed>
     */
    private function addLogEntry(McpToken $token, User $user, array $args, string $ipHash): array
    {
        $page = $this->writablePage($token, $user, self::requiredInt($args, 'page_id'));
        $pageId = (int) $page['id'];
        if ($page['type'] !== 'log') {
            throw new McpToolException('Die Seite ist kein Logbuch.');
        }

        $rawValues = $args['values'] ?? [];
        if (!is_array($rawValues)) {
            throw new McpToolException('values muss ein Objekt aus Spaltenname und Wert sein.');
        }

        // Spalten dürfen per Name oder ID angesprochen werden - Modelle
        // arbeiten lieber mit Namen, als erst IDs nachzuschlagen.
        $byName = [];
        $byId = [];
        foreach ($this->logs->columns($user, $pageId) as $column) {
            $byName[mb_strtolower((string) $column['name'])] = (int) $column['id'];
            $byId[(int) $column['id']] = true;
        }
        $values = [];
        foreach ($rawValues as $key => $value) {
            $key = (string) $key;
            $columnId = ctype_digit($key) && isset($byId[(int) $key])
                ? (int) $key
                : ($byName[mb_strtolower(trim($key))] ?? null);
            if ($columnId === null) {
                throw new McpToolException("Unbekannte Spalte: {$key}");
            }
            $values[$columnId] = $value;
        }

        $entry = $this->logs->createEntry($user, $pageId, $args['occurred_at'] ?? null, $values);
        $this->audit($user, $token, 'mcp_log_entry_created', $pageId, $ipHash, ['entry_id' => (int) $entry['id']]);

        return ['entry' => ['id' => (int) $entry['id'], 'occurred_at' => $entry['occurred_at']]];
    }

    // ---------------------------------------------------------------------
    // Hilfen

    /**
     * Die zweite Schranke neben dem Schreibrecht des Nutzers: der Schreibmodus
     * des Tokens und - im Modus "selected" - die Freigabe der Seite.
     *
     * @return array<string, mixed>
     */
    private function writablePage(McpToken $token, User $user, int $pageId): array
    {
        $page = $this->pages->find($user, $pageId);
        if ($token->writeMode === McpToken::WRITE_NONE) {
            throw new McpToolException('Dieser MCP-Zugang darf nur lesen.');
        }
        if (
            $token->writeMode === McpToken::WRITE_SELECTED
            && !$this->tokens->isPageGranted($user, (int) $page['id'])
        ) {
            throw new McpToolException(
                'Diese Seite ist nicht für MCP-Clients freigegeben. Der Nutzer kann sie im Teilen-Dialog'
                . ' der Seite freigeben oder ihr ganzes Notizbuch über dessen Menü („Für MCP freigeben").',
            );
        }
        $this->pages->assertCanWrite($user, (int) $page['id']);

        return $page;
    }

    /** @param array<string, mixed> $page */
    private function mcpCanWrite(McpToken $token, bool $granted, array $page): bool
    {
        if (($page['can_edit'] ?? false) !== true) {
            return false;
        }

        return match ($token->writeMode) {
            McpToken::WRITE_ALL => true,
            McpToken::WRITE_SELECTED => $granted,
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function pageMeta(McpToken $token, User $user, array $page): array
    {
        $granted = $this->tokens->isPageGranted($user, (int) $page['id']);

        return ['page' => $this->summary($token, $granted, $page)];
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     * @return list<array<string, mixed>>
     */
    private function pageSummaries(McpToken $token, User $user, array $pages): array
    {
        $granted = $this->tokens->grantedPageIds(
            $user,
            array_values(array_map(static fn (array $page): int => (int) $page['id'], $pages)),
        );

        return array_values(array_map(
            fn (array $page): array => $this->summary($token, isset($granted[(int) $page['id']]), $page)
                + ['preview' => $page['preview'] ?? null],
            $pages,
        ));
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function summary(McpToken $token, bool $granted, array $page): array
    {
        return [
            'id' => (int) $page['id'],
            'title' => (string) $page['title'],
            'type' => (string) $page['type'],
            'notebook_id' => isset($page['notebook_id']) ? (int) $page['notebook_id'] : null,
            'notebook_name' => $page['notebook_name'] ?? null,
            'created_at' => $page['created_at'] ?? null,
            'updated_at' => $page['updated_at'] ?? null,
            'is_encrypted' => (bool) ($page['is_encrypted'] ?? false),
            'is_shared' => (bool) ($page['is_shared'] ?? false),
            'mcp_can_write' => $this->mcpCanWrite($token, $granted, $page),
        ];
    }

    /**
     * Markdown in ein Dokument; Bilder, die schon in der Notiz stehen, behalten
     * dabei ihre Attribute (Größe, Bildnotizen), die Markdown nicht ausdrücken
     * kann. Fremde oder unbekannte Bildadressen bleiben bloßer Text.
     *
     * @param array<string, array<string, mixed>> $images src => Attribute des vorhandenen Bildes
     * @return array{type: string, content: list<array<string, mixed>>}
     */
    private function documentFromMarkdown(string $markdown, array $images): array
    {
        if (strlen($markdown) > self::MAX_MARKDOWN_BYTES) {
            throw new McpToolException('Der Text ist zu lang (höchstens 400 KB Markdown).');
        }

        $document = $this->converter->toDocument(
            $markdown,
            static fn (string $target, string $label, bool $asImage): ?array => $asImage && isset($images[$target])
                ? ['kind' => 'image', 'src' => $target]
                : null,
        );

        $content = array_values(array_map(
            fn (array $node): array => $this->restoreImageAttrs($node, $images),
            array_filter((array) ($document['content'] ?? []), 'is_array'),
        ));

        return ['type' => 'doc', 'content' => $content];
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, array<string, mixed>> $images
     * @return array<string, mixed>
     */
    private function restoreImageAttrs(array $node, array $images): array
    {
        if (($node['type'] ?? null) === 'image') {
            $src = (string) ($node['attrs']['src'] ?? '');
            if (isset($images[$src])) {
                $attrs = $images[$src];
                if (isset($node['attrs']['alt'])) {
                    $attrs['alt'] = $node['attrs']['alt'];
                }
                $node['attrs'] = $attrs;
            }

            return $node;
        }

        if (isset($node['content']) && is_array($node['content'])) {
            $node['content'] = array_map(
                fn (mixed $child): mixed => is_array($child) ? $this->restoreImageAttrs($child, $images) : $child,
                $node['content'],
            );
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, array<string, mixed>>
     */
    private function imageAttrs(array $node): array
    {
        $images = [];
        if (($node['type'] ?? null) === 'image' && is_array($node['attrs'] ?? null)) {
            $src = (string) ($node['attrs']['src'] ?? '');
            if (preg_match(self::ATTACHMENT_SRC, $src) === 1) {
                $images[$src] = $node['attrs'];
            }
        }
        foreach ((array) ($node['content'] ?? []) as $child) {
            if (is_array($child)) {
                $images += $this->imageAttrs($child);
            }
        }

        return $images;
    }

    /** @param array<string, mixed> $document */
    private function assertDocumentSize(array $document): void
    {
        $encoded = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || strlen($encoded) > self::MAX_DOCUMENT_BYTES) {
            throw new McpToolException('Der Inhalt ist für eine Notiz zu umfangreich.');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function audit(
        User $user,
        McpToken $token,
        string $action,
        int $pageId,
        string $ipHash,
        array $metadata = [],
    ): void {
        $this->auditLog->log($user->id, $action, 'page', $pageId, $ipHash, [
            'mcp_token_id' => $token->id,
            'mcp_token_label' => $token->label,
        ] + array_filter($metadata, static fn (mixed $value): bool => $value !== null));
    }

    /** @param array<array-key, mixed> $args */
    private static function requiredInt(array $args, string $key): int
    {
        $value = self::optionalInt($args, $key);
        if ($value === null) {
            throw new McpToolException("Parameter {$key} fehlt.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $args */
    private static function optionalInt(array $args, string $key): ?int
    {
        $value = $args[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1) {
            return (int) $value;
        }
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        throw new McpToolException("Parameter {$key} muss eine ganze Zahl sein.");
    }

    /** @param array<array-key, mixed> $args */
    private static function requiredString(array $args, string $key): string
    {
        $value = self::optionalString($args, $key);
        if ($value === null) {
            throw new McpToolException("Parameter {$key} fehlt.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $args */
    private static function optionalString(array $args, string $key): ?string
    {
        $value = $args[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new McpToolException("Parameter {$key} muss Text sein.");
        }

        return $value;
    }

    /** @param array<array-key, mixed> $args */
    private static function optionalBool(array $args, string $key): ?bool
    {
        $value = $args[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_bool($value)) {
            throw new McpToolException("Parameter {$key} muss true oder false sein.");
        }

        return $value;
    }

    /**
     * Beschreibungen und Eingabeschemata aller Werkzeuge.
     *
     * @return array<string, array<string, mixed>>
     */
    private function allDefinitions(): array
    {
        $pageId = ['type' => 'integer', 'description' => 'ID der Seite.'];
        $readOnly = ['readOnlyHint' => true, 'openWorldHint' => false];
        $additive = ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false];

        return [
            'list_notebooks' => [
                'title' => 'Notizbücher auflisten',
                'description' => 'Listet die Notizbücher des Nutzers (eigene und mit ihm geteilte).'
                    . ' mcp_can_write zeigt, ob dieser Client Seiten darin ändern darf.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
                'annotations' => $readOnly,
            ],
            'list_pages' => [
                'title' => 'Seiten auflisten',
                'description' => 'Listet Seiten (Notizen, Aufgabenlisten, Logbücher), zuletzt geänderte zuerst.'
                    . ' Ohne notebook_id erscheinen alle eigenen Seiten und die mit dem Nutzer geteilten.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'notebook_id' => ['type' => 'integer', 'description' => 'Nur Seiten dieses Notizbuchs.'],
                        'type' => ['type' => 'string', 'enum' => self::PAGE_TYPES],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT],
                        'offset' => ['type' => 'integer', 'minimum' => 0],
                    ],
                ],
                'annotations' => $readOnly,
            ],
            'search_pages' => [
                'title' => 'Seiten durchsuchen',
                'description' => 'Volltextsuche über Titel, Notiztext, Aufgaben, Logbuch-Werte und Dateinamen'
                    . ' (Teilwort, ohne Rücksicht auf Groß-/Kleinschreibung).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                        'notebook_id' => ['type' => 'integer', 'description' => 'Nur in diesem Notizbuch suchen.'],
                        'offset' => ['type' => 'integer', 'minimum' => 0],
                    ],
                    'required' => ['query'],
                ],
                'annotations' => $readOnly,
            ],
            'read_page' => [
                'title' => 'Seite lesen',
                'description' => 'Liest eine Seite. Notizen kommen als Markdown mit ihrer Version (für'
                    . ' update_note), Aufgabenlisten als Kapitel mit Aufgaben, Logbücher als Spalten mit'
                    . ' Einträgen. page.mcp_can_write zeigt, ob dieser Client die Seite ändern darf.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['page_id' => $pageId],
                    'required' => ['page_id'],
                ],
                'annotations' => $readOnly,
            ],
            'create_note' => [
                'title' => 'Notiz anlegen',
                'description' => 'Legt eine neue Notiz an, optional mit Markdown-Inhalt (Überschriften H1-H3,'
                    . ' Listen, Checklisten, Tabellen, Codeblöcke, Zitate, fett/kursiv/durchgestrichen/Code,'
                    . ' Links).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                        'markdown' => ['type' => 'string'],
                        'notebook_id' => ['type' => 'integer', 'description' => 'Ziel-Notizbuch (optional).'],
                    ],
                    'required' => ['title'],
                ],
                'annotations' => $additive,
            ],
            'update_note' => [
                'title' => 'Notiz ändern',
                'description' => 'Ändert eine Notiz. Modus "append" (Vorgabe) hängt das Markdown unten an;'
                    . ' Modus "replace" ersetzt den gesamten Inhalt - vorher lesen und vorhandene Bildverweise'
                    . ' wie ![](/api/attachments/...) übernehmen, sonst entfallen die Bilder. Vor jedem Ersetzen'
                    . ' entsteht ein Versionsstand im Verlauf. expected_version aus read_page verhindert, dass'
                    . ' gleichzeitige Änderungen überschrieben werden. title benennt die Seite optional um.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'page_id' => $pageId,
                        'markdown' => ['type' => 'string'],
                        'mode' => ['type' => 'string', 'enum' => ['append', 'replace']],
                        'expected_version' => ['type' => 'integer'],
                        'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                    ],
                    'required' => ['page_id'],
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => false],
            ],
            'add_task' => [
                'title' => 'Aufgabe anlegen',
                'description' => 'Legt eine Aufgabe in einer Aufgabenliste an. Ohne category_id landet sie im'
                    . ' ersten Kapitel.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'page_id' => $pageId,
                        'title' => ['type' => 'string', 'minLength' => 1],
                        'category_id' => ['type' => 'integer', 'description' => 'Kapitel (aus read_page).'],
                        'description' => ['type' => 'string'],
                        'responsible' => ['type' => 'string'],
                        'due_date' => ['type' => 'string', 'description' => 'JJJJ-MM-TT'],
                        'link' => ['type' => 'string', 'description' => 'http(s)-Adresse'],
                        'is_done' => ['type' => 'boolean'],
                    ],
                    'required' => ['page_id', 'title'],
                ],
                'annotations' => $additive,
            ],
            'update_task' => [
                'title' => 'Aufgabe ändern',
                'description' => 'Ändert eine Aufgabe, etwa um sie abzuhaken. Nur angegebene Felder ändern sich;'
                    . ' null leert ein optionales Feld.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer'],
                        'title' => ['type' => 'string', 'minLength' => 1],
                        'is_done' => ['type' => 'boolean'],
                        'description' => ['type' => ['string', 'null']],
                        'responsible' => ['type' => ['string', 'null']],
                        'due_date' => ['type' => ['string', 'null'], 'description' => 'JJJJ-MM-TT'],
                        'link' => ['type' => ['string', 'null']],
                        'expected_version' => ['type' => 'integer'],
                    ],
                    'required' => ['task_id'],
                ],
                'annotations' => $additive,
            ],
            'add_log_entry' => [
                'title' => 'Logbuch-Eintrag anlegen',
                'description' => 'Legt einen Eintrag in einem Logbuch an. values ordnet Spaltennamen (oder'
                    . ' -IDs) ihre Werte zu; read_page nennt Spalten und Spaltenarten.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'page_id' => $pageId,
                        'occurred_at' => ['type' => 'string', 'description' => 'ISO 8601, Vorgabe: jetzt'],
                        'values' => ['type' => 'object'],
                    ],
                    'required' => ['page_id'],
                ],
                'annotations' => $additive,
            ],
        ];
    }
}
