<?php

declare(strict_types=1);

namespace App\Domain\Mcp;

use App\Domain\NotebookService;
use App\Domain\PageService;
use App\Domain\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\McpTokenRepository;
use App\Repositories\UserRepository;
use App\Support\ForbiddenException;
use App\Support\NotFoundException;
use App\Support\ValidationException;

/**
 * Verwaltung der MCP-Token (FR-MCP-01/02) und der Schreibfreigaben einzelner
 * Seiten (FR-MCP-03). Sichtbar, änderbar und widerrufbar sind ausschließlich
 * die eigenen Token; der Rohwert wird nur beim Erzeugen einmal ausgegeben.
 */
final class McpTokenService
{
    /** Erkennbares Präfix, damit ein versehentlich geteilter Token auffällt. */
    public const TOKEN_PREFIX = 'nmcp_';

    public const NOTEBOOK_DEFAULT = 'default';
    public const NOTEBOOK_WRITE = 'write';
    public const NOTEBOOK_EXCLUDED = 'excluded';
    public const NOTEBOOK_MODES = [self::NOTEBOOK_DEFAULT, self::NOTEBOOK_WRITE, self::NOTEBOOK_EXCLUDED];

    private const MAX_LABEL_LENGTH = 60;
    private const MAX_TOKENS_PER_USER = 20;

    public function __construct(
        private readonly McpTokenRepository $tokens,
        private readonly UserRepository $users,
        private readonly PageService $pages,
        private readonly NotebookService $notebooks,
        private readonly AuditLogRepository $auditLog,
    ) {
    }

    /**
     * @return array<string, mixed> Serialisierter Token zusätzlich mit `token` (Rohwert, nur jetzt sichtbar).
     */
    public function issue(User $user, string $label, mixed $writeMode, mixed $canCreate, string $ipHash): array
    {
        $label = $this->validatedLabel($label);
        $writeMode = $this->validatedWriteMode($writeMode ?? McpToken::WRITE_NONE);
        $canCreate = $this->validatedFlag($canCreate ?? false);

        if (count($this->tokens->allActiveForUser($user->id)) >= self::MAX_TOKENS_PER_USER) {
            throw new ValidationException(
                'Es sind bereits ' . self::MAX_TOKENS_PER_USER . ' MCP-Token angelegt.'
                . ' Bitte zuerst einen nicht mehr benötigten widerrufen.',
            );
        }

        $rawToken = self::TOKEN_PREFIX . bin2hex(random_bytes(32));
        $id = $this->tokens->create($user->id, $label, hash('sha256', $rawToken), $writeMode, $canCreate);

        $this->auditLog->log($user->id, 'mcp_token_issued', 'mcp_token', $id, $ipHash, [
            'label' => $label,
            'write_mode' => $writeMode,
            'can_create' => $canCreate,
        ]);

        $row = $this->tokens->findActiveById($id);
        assert($row !== null);

        return $this->serialize($row) + ['token' => $rawToken];
    }

    /** @return list<array<string, mixed>> */
    public function listFor(User $user): array
    {
        return array_values(array_map([$this, 'serialize'], $this->tokens->allActiveForUser($user->id)));
    }

    /**
     * Rechte und Name eines bestehenden Tokens ändern - ohne neuen Token, damit
     * der Client nicht neu eingerichtet werden muss.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(User $user, int $id, array $input, string $ipHash): array
    {
        $this->requireOwn($user, $id);

        $fields = [];
        if (array_key_exists('label', $input)) {
            $fields['label'] = $this->validatedLabel(is_string($input['label']) ? $input['label'] : '');
        }
        if (array_key_exists('write_mode', $input)) {
            $fields['write_mode'] = $this->validatedWriteMode($input['write_mode']);
        }
        if (array_key_exists('can_create', $input)) {
            $fields['can_create'] = $this->validatedFlag($input['can_create']);
        }

        $this->tokens->update($id, $fields);
        $this->auditLog->log($user->id, 'mcp_token_updated', 'mcp_token', $id, $ipHash, $fields);

        $row = $this->tokens->findActiveById($id);
        assert($row !== null);

        return $this->serialize($row);
    }

    public function revoke(User $user, int $id, string $ipHash): void
    {
        $this->requireOwn($user, $id);
        $this->tokens->revoke($id);
        $this->auditLog->log($user->id, 'mcp_token_revoked', 'mcp_token', $id, $ipHash);
    }

    /**
     * Löst einen rohen Bearer-Token auf. Ein gesperrter Nutzer verliert damit
     * sofort auch den MCP-Zugang.
     *
     * @return array{token: McpToken, user: User}|null
     */
    public function resolve(string $rawToken): ?array
    {
        if (!str_starts_with($rawToken, self::TOKEN_PREFIX)) {
            return null;
        }
        $row = $this->tokens->findActiveByHash(hash('sha256', $rawToken));
        if ($row === null) {
            return null;
        }

        $user = $this->users->findById((int) $row['user_id']);
        if ($user === null || !$user->isActive) {
            return null;
        }

        $this->tokens->touchLastUsed((int) $row['id']);

        return ['token' => McpToken::fromRow($row), 'user' => $user];
    }

    /**
     * Zustand der Seitenfreigabe für den Teilen-Dialog: ob die Seite selbst
     * oder ihr Notizbuch freigegeben ist und wie viele eigene Token davon
     * betroffen sind (Modus "selected") bzw. ohnehin überall schreiben ("all").
     *
     * @return array{writable: bool, notebook_writable: bool, notebook_excluded: bool, selected_tokens: int, all_tokens: int}
     */
    public function pageAccess(User $user, int $pageId): array
    {
        $page = $this->pages->find($user, $pageId);
        $pageId = (int) $page['id'];

        return [
            'writable' => $this->tokens->hasPageGrant($user->id, $pageId),
            'notebook_writable' => $this->tokens->hasNotebookGrantForPage($user->id, $pageId),
            'notebook_excluded' => $this->tokens->excludedPageIds($user->id, [$pageId]) !== [],
        ] + $this->tokenCounts($user);
    }

    /**
     * Seite für die eigenen MCP-Clients freigeben oder die Freigabe entziehen.
     * Freigeben setzt eigenes Schreibrecht voraus; entziehen geht immer, solange
     * die Seite sichtbar ist.
     *
     * @return array{writable: bool, notebook_writable: bool, notebook_excluded: bool, selected_tokens: int, all_tokens: int}
     */
    public function setPageWritable(User $user, int $pageId, bool $writable, string $ipHash): array
    {
        $page = $this->pages->find($user, $pageId);
        $pageId = (int) $page['id'];

        if ($writable) {
            $this->pages->assertCanWrite($user, $pageId);
            $this->tokens->grantPage($user->id, $pageId);
        } else {
            $this->tokens->revokePageGrant($user->id, $pageId);
        }
        $this->auditLog->log(
            $user->id,
            $writable ? 'mcp_page_granted' : 'mcp_page_revoked',
            'page',
            $pageId,
            $ipHash,
        );

        return $this->pageAccess($user, $pageId);
    }

    /**
     * Notizbücher für die MCP-Übersicht mit ihrem Zustand: Standard, für MCP
     * beschreibbar (FR-MCP-03) oder für MCP gesperrt (FR-MCP-08). Gesperrt
     * werden kann jedes sichtbare Notizbuch, freigegeben nur mit Schreibrecht.
     *
     * @return array{notebooks: list<array{id: int, name: string, owner_name: ?string, is_owner: bool, can_edit: bool, mode: string, writable: bool, excluded: bool}>, selected_tokens: int, all_tokens: int}
     */
    public function notebookAccessList(User $user): array
    {
        $granted = $this->tokens->grantedNotebookIds($user->id);
        $excluded = $this->tokens->excludedNotebookIds($user->id);
        $notebooks = [];
        foreach ($this->notebooks->listWithShared($user) as $notebook) {
            $id = (int) $notebook['id'];
            $mode = isset($excluded[$id]) ? self::NOTEBOOK_EXCLUDED : (isset($granted[$id]) ? self::NOTEBOOK_WRITE : self::NOTEBOOK_DEFAULT);
            $notebooks[] = [
                'id' => $id,
                'name' => (string) $notebook['name'],
                'owner_name' => isset($notebook['owner_name']) ? (string) $notebook['owner_name'] : null,
                'is_owner' => ($notebook['is_owner'] ?? false) === true,
                'can_edit' => self::canEditNotebook($notebook),
                'mode' => $mode,
                'writable' => $mode === self::NOTEBOOK_WRITE,
                'excluded' => $mode === self::NOTEBOOK_EXCLUDED,
            ];
        }

        return ['notebooks' => $notebooks] + $this->tokenCounts($user);
    }

    /**
     * Zustand eines Notizbuchs für die eigenen MCP-Clients setzen:
     * - default:  lesbar, beschreibbar nur im Modus "Alle Seiten"
     * - write:    alle Seiten darin beschreibbar, auch künftige (FR-MCP-03)
     * - excluded: Notizbuch samt Seiten für MCP unsichtbar (FR-MCP-08)
     * Freigabe und Sperre schließen sich aus; der neue Zustand ersetzt den alten.
     *
     * @return array{mode: string, writable: bool, excluded: bool, selected_tokens: int, all_tokens: int}
     */
    public function setNotebookMode(User $user, int $notebookId, mixed $mode, string $ipHash): array
    {
        if (!is_string($mode) || !in_array($mode, self::NOTEBOOK_MODES, true)) {
            throw new ValidationException('Ungültiger Zustand. Erlaubt: default, write, excluded.');
        }
        $notebook = $this->visibleNotebook($user, $notebookId);
        if ($mode === self::NOTEBOOK_WRITE && !self::canEditNotebook($notebook)) {
            throw new ForbiddenException('In diesem Notizbuch darfst du nur lesen.');
        }

        $previous = $this->notebookMode($user, $notebookId);
        if ($mode === self::NOTEBOOK_WRITE) {
            $this->tokens->removeNotebookExclusion($user->id, $notebookId);
            $this->tokens->grantNotebook($user->id, $notebookId);
        } elseif ($mode === self::NOTEBOOK_EXCLUDED) {
            $this->tokens->revokeNotebookGrant($user->id, $notebookId);
            $this->tokens->excludeNotebook($user->id, $notebookId);
        } else {
            $this->tokens->revokeNotebookGrant($user->id, $notebookId);
            $this->tokens->removeNotebookExclusion($user->id, $notebookId);
        }

        if ($previous !== $mode) {
            $this->auditLog->log($user->id, match ($mode) {
                self::NOTEBOOK_WRITE => 'mcp_notebook_granted',
                self::NOTEBOOK_EXCLUDED => 'mcp_notebook_excluded',
                default => $previous === self::NOTEBOOK_EXCLUDED ? 'mcp_notebook_included' : 'mcp_notebook_revoked',
            }, 'notebook', $notebookId, $ipHash);
        }

        return [
            'mode' => $mode,
            'writable' => $mode === self::NOTEBOOK_WRITE,
            'excluded' => $mode === self::NOTEBOOK_EXCLUDED,
        ] + $this->tokenCounts($user);
    }

    /**
     * Kurzform von setNotebookMode für die Freigabe (true) bzw. ihre Rücknahme.
     * Die Rücknahme hebt keine Sperre auf.
     *
     * @return array{mode: string, writable: bool, excluded: bool, selected_tokens: int, all_tokens: int}
     */
    public function setNotebookWritable(User $user, int $notebookId, bool $writable, string $ipHash): array
    {
        if (!$writable && $this->notebookMode($user, $notebookId) === self::NOTEBOOK_EXCLUDED) {
            $this->visibleNotebook($user, $notebookId);

            return ['mode' => self::NOTEBOOK_EXCLUDED, 'writable' => false, 'excluded' => true]
                + $this->tokenCounts($user);
        }

        return $this->setNotebookMode(
            $user,
            $notebookId,
            $writable ? self::NOTEBOOK_WRITE : self::NOTEBOOK_DEFAULT,
            $ipHash,
        );
    }

    private function notebookMode(User $user, int $notebookId): string
    {
        if (isset($this->tokens->excludedNotebookIds($user->id)[$notebookId])) {
            return self::NOTEBOOK_EXCLUDED;
        }

        return isset($this->tokens->grantedNotebookIds($user->id)[$notebookId])
            ? self::NOTEBOOK_WRITE
            : self::NOTEBOOK_DEFAULT;
    }

    /** @return array<int, true> Notizbuch-ID => für MCP gesperrt */
    public function excludedNotebookIds(User $user): array
    {
        return $this->tokens->excludedNotebookIds($user->id);
    }

    public function isPageExcluded(User $user, int $pageId): bool
    {
        return $this->tokens->excludedPageIds($user->id, [$pageId]) !== [];
    }

    /**
     * @param list<int> $pageIds
     * @return array<int, true> Seiten-ID => in gesperrtem Notizbuch
     */
    public function excludedPageIds(User $user, array $pageIds): array
    {
        return $this->tokens->excludedPageIds($user->id, $pageIds);
    }

    /** @return array<int, true> Notizbuch-ID => freigegeben */
    public function grantedNotebookIds(User $user): array
    {
        return $this->tokens->grantedNotebookIds($user->id);
    }

    /** Freigabe ohne Prüfung und Audit - für vom MCP-Client selbst angelegte Seiten. */
    public function grantCreatedPage(User $user, int $pageId): void
    {
        $this->tokens->grantPage($user->id, $pageId);
    }

    /** Seite direkt oder über ihr Notizbuch freigegeben. */
    public function isPageGranted(User $user, int $pageId): bool
    {
        return $this->tokens->hasPageGrant($user->id, $pageId)
            || $this->tokens->hasNotebookGrantForPage($user->id, $pageId);
    }

    /**
     * @param list<int> $pageIds
     * @return array<int, true>
     */
    public function grantedPageIds(User $user, array $pageIds): array
    {
        return $this->tokens->grantedPageIds($user->id, $pageIds);
    }

    /** @return array{selected_tokens: int, all_tokens: int} */
    private function tokenCounts(User $user): array
    {
        $selected = 0;
        $all = 0;
        foreach ($this->tokens->allActiveForUser($user->id) as $row) {
            match ((string) $row['write_mode']) {
                McpToken::WRITE_SELECTED => ++$selected,
                McpToken::WRITE_ALL => ++$all,
                default => null,
            };
        }

        return ['selected_tokens' => $selected, 'all_tokens' => $all];
    }

    /** @return array<string, mixed> */
    private function visibleNotebook(User $user, int $notebookId): array
    {
        foreach ($this->notebooks->listWithShared($user) as $notebook) {
            if ((int) $notebook['id'] === $notebookId) {
                return $notebook;
            }
        }

        throw new NotFoundException('Notizbuch nicht gefunden.');
    }

    /** @param array<string, mixed> $notebook Zeile aus NotebookService::listWithShared */
    public static function canEditNotebook(array $notebook): bool
    {
        return ($notebook['is_owner'] ?? false) === true
            || ($notebook['share_permission'] ?? 'write') !== 'read';
    }

    private function requireOwn(User $user, int $id): void
    {
        $row = $this->tokens->findActiveById($id);
        if ($row === null || (int) $row['user_id'] !== $user->id) {
            throw new NotFoundException('MCP-Token nicht gefunden.');
        }
    }

    private function validatedLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            throw new ValidationException('Bitte einen Namen für den Client angeben.');
        }
        if (mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            throw new ValidationException('Der Name darf höchstens ' . self::MAX_LABEL_LENGTH . ' Zeichen lang sein.');
        }

        return $label;
    }

    private function validatedWriteMode(mixed $mode): string
    {
        if (!is_string($mode) || !in_array($mode, McpToken::WRITE_MODES, true)) {
            throw new ValidationException('Ungültiger Schreibmodus.');
        }

        return $mode;
    }

    private function validatedFlag(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new ValidationException('Ungültiger Wert für „Notizen anlegen".');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serialize(array $row): array
    {
        $token = McpToken::fromRow($row);

        return [
            'id' => $token->id,
            'label' => $token->label,
            'write_mode' => $token->writeMode,
            'can_create' => $token->canCreate,
            'created_at' => $row['created_at'],
            'last_used_at' => $row['last_used_at'],
        ];
    }
}
