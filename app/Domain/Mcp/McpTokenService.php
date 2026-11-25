<?php

declare(strict_types=1);

namespace App\Domain\Mcp;

use App\Domain\PageService;
use App\Domain\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\McpTokenRepository;
use App\Repositories\UserRepository;
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

    private const MAX_LABEL_LENGTH = 60;
    private const MAX_TOKENS_PER_USER = 20;

    public function __construct(
        private readonly McpTokenRepository $tokens,
        private readonly UserRepository $users,
        private readonly PageService $pages,
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
     * Zustand der Seitenfreigabe für den Teilen-Dialog: ob die Seite
     * freigegeben ist und wie viele eigene Token davon überhaupt betroffen
     * sind (Modus "selected") bzw. ohnehin überall schreiben dürfen ("all").
     *
     * @return array{writable: bool, selected_tokens: int, all_tokens: int}
     */
    public function pageAccess(User $user, int $pageId): array
    {
        $page = $this->pages->find($user, $pageId);

        $selected = 0;
        $all = 0;
        foreach ($this->tokens->allActiveForUser($user->id) as $row) {
            match ((string) $row['write_mode']) {
                McpToken::WRITE_SELECTED => ++$selected,
                McpToken::WRITE_ALL => ++$all,
                default => null,
            };
        }

        return [
            'writable' => $this->tokens->hasPageGrant($user->id, (int) $page['id']),
            'selected_tokens' => $selected,
            'all_tokens' => $all,
        ];
    }

    /**
     * Seite für die eigenen MCP-Clients freigeben oder die Freigabe entziehen.
     * Freigeben setzt eigenes Schreibrecht voraus; entziehen geht immer, solange
     * die Seite sichtbar ist.
     *
     * @return array{writable: bool, selected_tokens: int, all_tokens: int}
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

    /** Freigabe ohne Prüfung und Audit - für vom MCP-Client selbst angelegte Seiten. */
    public function grantCreatedPage(User $user, int $pageId): void
    {
        $this->tokens->grantPage($user->id, $pageId);
    }

    public function isPageGranted(User $user, int $pageId): bool
    {
        return $this->tokens->hasPageGrant($user->id, $pageId);
    }

    /**
     * @param list<int> $pageIds
     * @return array<int, true>
     */
    public function grantedPageIds(User $user, array $pageIds): array
    {
        return $this->tokens->grantedPageIds($user->id, $pageIds);
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
