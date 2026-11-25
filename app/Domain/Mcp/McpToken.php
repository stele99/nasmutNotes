<?php

declare(strict_types=1);

namespace App\Domain\Mcp;

/**
 * Ein aufgelöster MCP-Token samt seinen Rechten (FR-MCP-02/03).
 */
final readonly class McpToken
{
    public const WRITE_NONE = 'none';
    public const WRITE_SELECTED = 'selected';
    public const WRITE_ALL = 'all';
    public const WRITE_MODES = [self::WRITE_NONE, self::WRITE_SELECTED, self::WRITE_ALL];

    public function __construct(
        public int $id,
        public int $userId,
        public string $label,
        public string $writeMode,
        public bool $canCreate,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $mode = (string) $row['write_mode'];

        return new self(
            (int) $row['id'],
            (int) $row['user_id'],
            (string) $row['label'],
            in_array($mode, self::WRITE_MODES, true) ? $mode : self::WRITE_NONE,
            (int) $row['can_create'] === 1,
        );
    }

    /** Darf der Client überhaupt irgendetwas ändern? Steuert die Werkzeugliste. */
    public function canWriteAnything(): bool
    {
        return $this->writeMode !== self::WRITE_NONE || $this->canCreate;
    }
}
