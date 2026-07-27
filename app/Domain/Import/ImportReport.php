<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * Ergebnis eines Archiv-Imports (FR-IMP-23). Zählt, was angelegt wurde, und
 * sammelt jeden übersprungenen oder gescheiterten Eintrag mit Begründung —
 * ein Import, der stillschweigend Teile weglässt, wäre nicht überprüfbar.
 */
final class ImportReport
{
    /** Mehr Einzelmeldungen liest niemand; die Zähler bleiben vollständig. */
    private const MAX_DETAILS = 200;

    public int $pages = 0;
    public int $images = 0;
    public int $files = 0;
    public int $deadLinks = 0;
    public int $unusedFiles = 0;
    public int $skippedCount = 0;
    public int $failedCount = 0;

    /** @var list<array{name: string, reason: string}> */
    private array $skipped = [];

    /** @var list<array{name: string, reason: string}> */
    private array $failed = [];

    public function skip(string $name, string $reason): void
    {
        ++$this->skippedCount;
        if (count($this->skipped) < self::MAX_DETAILS) {
            $this->skipped[] = ['name' => $name, 'reason' => $reason];
        }
    }

    public function fail(string $name, string $reason): void
    {
        ++$this->failedCount;
        if (count($this->failed) < self::MAX_DETAILS) {
            $this->failed[] = ['name' => $name, 'reason' => $reason];
        }
    }

    /**
     * @return array{
     *     pages: int, images: int, files: int, dead_links: int, unused_files: int,
     *     skipped_count: int, failed_count: int,
     *     skipped: list<array{name: string, reason: string}>,
     *     failed: list<array{name: string, reason: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'pages' => $this->pages,
            'images' => $this->images,
            'files' => $this->files,
            'dead_links' => $this->deadLinks,
            'unused_files' => $this->unusedFiles,
            'skipped_count' => $this->skippedCount,
            'failed_count' => $this->failedCount,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
        ];
    }
}
