<?php

declare(strict_types=1);

namespace App\Domain\Log;

use App\Domain\Geo\ReverseGeocoder;
use App\Domain\PageService;
use App\Domain\User;
use App\Repositories\LogRepository;
use App\Repositories\PageRepository;
use App\Support\NotFoundException;
use App\Support\ValidationException;
use PDO;

/**
 * Logbuch-Seiten (FR-LOG-01..09): frei definierbare Spalten und Einträge, die
 * immer einen änderbaren Zeitpunkt tragen. Neueste Einträge stehen oben,
 * sortiert werden kann nach jeder Spalte.
 */
final class LogService
{
    public const MAX_COLUMNS = 12;

    /** Ohne eigene Spalten wäre ein frisches Logbuch nicht benutzbar. */
    public const DEFAULT_COLUMN_NAME = 'Eintrag';

    public function __construct(
        private readonly PDO $pdo,
        private readonly PageService $pages,
        private readonly PageRepository $pageRepository,
        private readonly LogRepository $log,
        private readonly ?ReverseGeocoder $geocoder = null,
    ) {
    }

    /**
     * Spalten und Einträge einer Seite.
     *
     * @return array{
     *     columns: array<int, array<string, mixed>>,
     *     entries: array<int, array<string, mixed>>,
     *     entry_count: int,
     *     sort: string,
     *     direction: string
     * }
     */
    public function board(User $user, int $pageId, ?string $sort = null, ?string $direction = null): array
    {
        $page = $this->requireLogPage($user, $pageId);
        $pageId = (int) $page['id'];

        $columns = $this->log->columnsForPage($pageId);
        $sortColumnId = $this->resolveSortColumn($sort, $columns);
        $sortColumnType = null;
        foreach ($columns as $column) {
            if ((int) $column['id'] === $sortColumnId) {
                $sortColumnType = (string) $column['type'];
                break;
            }
        }
        // Vorgabe ist absteigend: Das Jüngste gehört bei einem Logbuch nach oben.
        $ascending = $direction === 'asc';

        return [
            'columns' => $columns,
            'entries' => $this->log->entriesForPage($pageId, $sortColumnId, $ascending, $sortColumnType),
            'entry_count' => $this->log->countEntries($pageId),
            'sort' => $sortColumnId === null ? 'occurred_at' : (string) $sortColumnId,
            'direction' => $ascending ? 'asc' : 'desc',
        ];
    }

    /**
     * Legt die Spalte an, mit der ein neues Logbuch sofort brauchbar ist.
     * Wird beim Anlegen der Seite aufgerufen.
     */
    public function createDefaultColumns(int $pageId): void
    {
        $this->log->createColumn($pageId, self::DEFAULT_COLUMN_NAME, LogColumnType::Text->value, 0);
    }

    /** @return array<string, mixed> */
    public function createColumn(User $user, int $pageId, mixed $name, mixed $type): array
    {
        $page = $this->requireLogPage($user, $pageId);
        $this->pages->assertCanWrite($user, (int) $page['id']);

        if (count($this->log->columnsForPage((int) $page['id'])) >= self::MAX_COLUMNS) {
            throw new ValidationException('Ein Logbuch kann höchstens ' . self::MAX_COLUMNS . ' Spalten haben.');
        }

        return $this->log->createColumn(
            (int) $page['id'],
            $this->validatedName($name),
            LogColumnType::fromInput($type)->value,
            $this->log->nextColumnPosition((int) $page['id']),
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateColumn(User $user, int $columnId, array $input): array
    {
        $column = $this->requireOwnedColumn($user, $columnId);
        $fields = [];

        if (array_key_exists('name', $input)) {
            $fields['name'] = $this->validatedName($input['name']);
        }
        if (array_key_exists('position', $input)) {
            $fields['position'] = max(0, (int) $input['position']);
        }

        $this->log->updateColumn((int) $column['id'], $fields);
        $updated = $this->log->findColumn((int) $column['id']);
        assert($updated !== null);

        return $updated;
    }

    /**
     * Verschiebt eine Spalte um eine Stelle. Die Nachbarspalte tauscht dabei
     * den Platz, damit die Reihenfolge lückenlos bleibt.
     *
     * @return array<int, array<string, mixed>>
     */
    public function moveColumn(User $user, int $columnId, string $direction): array
    {
        $column = $this->requireOwnedColumn($user, $columnId);
        $columns = $this->log->columnsForPage((int) $column['page_id']);
        $index = array_search((int) $column['id'], array_map(
            static fn (array $item): int => (int) $item['id'],
            $columns,
        ), true);
        $target = $index + ($direction === 'up' ? -1 : 1);

        if (!is_int($index) || $target < 0 || $target >= count($columns)) {
            return $columns;
        }

        $this->pdo->beginTransaction();
        try {
            // Positionen neu durchzählen: Alte Bestände können Lücken oder
            // Dubletten enthalten, ein reiner Tausch wäre dann wirkungslos.
            [$columns[$index], $columns[$target]] = [$columns[$target], $columns[$index]];
            foreach ($columns as $position => $item) {
                $this->log->updateColumn((int) $item['id'], ['position' => $position]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return $this->log->columnsForPage((int) $column['page_id']);
    }

    public function deleteColumn(User $user, int $columnId): void
    {
        $column = $this->requireOwnedColumn($user, $columnId);
        $this->log->deleteColumn((int) $column['id']);
    }

    /**
     * Neuer Eintrag. Ohne Zeitangabe gilt der Zeitpunkt der Erfassung.
     *
     * @param array<array-key, mixed> $values Spalten-ID => Wert
     * @return array<string, mixed>
     */
    public function createEntry(User $user, int $pageId, mixed $occurredAt, array $values): array
    {
        $page = $this->requireLogPage($user, $pageId);
        $pageId = (int) $page['id'];
        $this->pages->assertCanWrite($user, $pageId);

        $columns = $this->indexedColumns($pageId);
        $normalized = $this->normalizeValues($columns, $values);

        $this->pdo->beginTransaction();
        try {
            $entryId = $this->log->createEntry(
                $pageId,
                $this->validatedTimestamp($occurredAt) ?? gmdate('Y-m-d\TH:i:s.v\Z'),
                $user->id,
            );
            foreach ($normalized as $columnId => $value) {
                if ($value !== null) {
                    $this->log->setValue($entryId, $columnId, $value);
                }
            }
            $this->pageRepository->touchUpdatedAt($pageId, gmdate('Y-m-d\TH:i:s.v\Z'));
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        $entry = $this->log->findEntry($entryId);
        assert($entry !== null);

        return $entry;
    }

    /**
     * Ändert Zeitpunkt und Werte eines Eintrags. Nur übermittelte Spalten
     * werden angefasst; ein leerer Wert löscht die Zelle.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateEntry(User $user, int $entryId, array $input): array
    {
        $entry = $this->requireOwnedEntry($user, $entryId);
        $pageId = (int) $entry['page_id'];
        $columns = $this->indexedColumns($pageId);

        $values = is_array($input['values'] ?? null) ? $input['values'] : [];
        $normalized = $this->normalizeValues($columns, $values);

        $this->pdo->beginTransaction();
        try {
            if (array_key_exists('occurred_at', $input)) {
                $occurredAt = $this->validatedTimestamp($input['occurred_at']);
                if ($occurredAt === null) {
                    throw new ValidationException('Ungültiger Zeitpunkt.');
                }
                $this->log->updateEntryTime((int) $entry['id'], $occurredAt);
            }

            foreach ($normalized as $columnId => $value) {
                if ($value === null) {
                    $this->log->clearValue((int) $entry['id'], $columnId);
                } else {
                    $this->log->setValue((int) $entry['id'], $columnId, $value);
                }
            }

            $this->log->touchEntry((int) $entry['id']);
            $this->pageRepository->touchUpdatedAt($pageId, gmdate('Y-m-d\TH:i:s.v\Z'));
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        $updated = $this->log->findEntry((int) $entry['id']);
        assert($updated !== null);

        return $updated;
    }

    public function deleteEntry(User $user, int $entryId): void
    {
        $entry = $this->requireOwnedEntry($user, $entryId);
        $this->log->deleteEntry((int) $entry['id']);
        $this->pageRepository->touchUpdatedAt((int) $entry['page_id'], gmdate('Y-m-d\TH:i:s.v\Z'));
    }

    /** @return array<int, array<string, mixed>> */
    public function columns(User $user, int $pageId): array
    {
        return $this->log->columnsForPage((int) $this->requireLogPage($user, $pageId)['id']);
    }

    /**
     * Werte je Spalte prüfen und in die Ablageform bringen.
     *
     * @param array<int, array<string, mixed>> $columns Spalten-ID => Spalte
     * @param array<array-key, mixed> $values
     * @return array<int, array{text: ?string, number: ?float, lat: ?float, lon: ?float}|null>
     */
    private function normalizeValues(array $columns, array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            $columnId = (int) $key;
            if (!isset($columns[$columnId])) {
                throw new ValidationException('Unbekannte Spalte im Eintrag.');
            }
            $type = LogColumnType::from((string) $columns[$columnId]['type']);
            if ($type !== LogColumnType::User) {
                $normalized[$columnId] = $this->normalizeValue($type, $value);
            }
        }

        return $normalized;
    }

    /** @return array{text: ?string, number: ?float, lat: ?float, lon: ?float}|null */
    private function normalizeValue(LogColumnType $type, mixed $value): ?array
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return match ($type) {
            LogColumnType::Text => $this->textValue($value),
            LogColumnType::Time => $this->timeValue($value),
            LogColumnType::Location => $this->locationValue($value),
            LogColumnType::Hours, LogColumnType::Number, LogColumnType::Money => $this->numberValue($type, $value),
            LogColumnType::Rating => $this->ratingValue($value),
            LogColumnType::User => null,
        };
    }

    /**
     * Bewertung als ganze Sternzahl von 0 bis 5 (FR-LOG-03). Die Zahl trägt
     * die Sortierung, die Sterne stehen zusätzlich als Text daneben - so
     * lesen Export und öffentliche Ansicht sie ohne eigene Umrechnung, genau
     * wie bei der Uhrzeit.
     *
     * @return array{text: ?string, number: ?float, lat: ?float, lon: ?float}
     */
    private function ratingValue(mixed $value): array
    {
        $raw = is_scalar($value) ? trim((string) $value) : '';
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            throw new ValidationException('Eine Bewertung braucht eine ganze Zahl von 0 bis 5.');
        }

        $stars = (int) $raw;
        if ($stars > LogColumnType::RATING_MAX) {
            throw new ValidationException('Eine Bewertung geht höchstens bis 5 Sterne.');
        }

        return [
            'text' => LogColumnType::ratingStars($stars),
            'number' => (float) $stars,
            'lat' => null,
            'lon' => null,
        ];
    }

    /** @return array{text: ?string, number: ?float, lat: ?float, lon: ?float} */
    private function textValue(mixed $value): array
    {
        if (!is_scalar($value)) {
            throw new ValidationException('Ungültiger Textwert.');
        }
        $text = trim((string) $value);
        if (mb_strlen($text) > 2000) {
            throw new ValidationException('Ein Textwert darf höchstens 2000 Zeichen lang sein.');
        }

        return ['text' => $text, 'number' => null, 'lat' => null, 'lon' => null];
    }

    /** @return array{text: ?string, number: ?float, lat: ?float, lon: ?float} */
    private function timeValue(mixed $value): array
    {
        $time = is_scalar($value) ? trim((string) $value) : '';
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $match) !== 1) {
            throw new ValidationException('Uhrzeiten müssen im Format HH:MM angegeben werden.');
        }

        // Zusätzlich als Minuten seit Mitternacht: So sortiert die Spalte
        // richtig und lässt sich auswerten.
        return [
            'text' => $time,
            'number' => (float) ((int) $match[1] * 60 + (int) $match[2]),
            'lat' => null,
            'lon' => null,
        ];
    }

    /** @return array{text: ?string, number: ?float, lat: ?float, lon: ?float} */
    private function locationValue(mixed $value): array
    {
        // Der Client schickt Beschriftung und Koordinaten getrennt; ein reiner
        // Text (etwa ein Ortsname von Hand) ist ebenfalls zulässig.
        if (is_scalar($value)) {
            return $this->textValue($value);
        }
        if (!is_array($value)) {
            throw new ValidationException('Ungültiger Standortwert.');
        }

        $location = PageService::validatedLocation($value);
        $label = isset($value['label']) && is_scalar($value['label']) ? trim((string) $value['label']) : '';

        if ($location === null && $label === '') {
            throw new ValidationException('Der Standort braucht Koordinaten oder eine Beschriftung.');
        }

        // Hat der Nutzer nur Koordinaten oder einen Kartenlink eingesetzt, wird
        // die Anschrift dazu ermittelt und angezeigt (FR-NOTE-26). Ein selbst
        // vergebener Name bleibt unangetastet.
        if ($location !== null && $this->needsAddress($label)) {
            $label = $this->geocoder?->lookup($location['lat'], $location['lon']) ?? $label;
        }

        if (mb_strlen($label) > 300) {
            $label = mb_substr($label, 0, 299) . '…';
        }

        return [
            'text' => $label !== '' ? $label : null,
            'number' => null,
            'lat' => $location['lat'] ?? null,
            'lon' => $location['lon'] ?? null,
        ];
    }

    /**
     * Eine Beschriftung ohne zusammenhängendes Wort ist keine: Dann stehen dort
     * nur Koordinaten oder ein Kartenlink, und die Anschrift ist die bessere
     * Anzeige.
     */
    private function needsAddress(string $label): bool
    {
        return $label === ''
            || preg_match('#^https?://#i', $label) === 1
            || preg_match('/\p{L}{2,}/u', $label) !== 1;
    }

    /** @return array{text: ?string, number: ?float, lat: ?float, lon: ?float} */
    private function numberValue(LogColumnType $type, mixed $value): array
    {
        if (is_int($value) || is_float($value)) {
            $raw = (string) $value;
        } else {
            $raw = is_scalar($value) ? trim((string) $value) : '';
            // Diktierte und getippte Zahlen kommen deutsch: „1.234,50". Nur wenn
            // ein Komma vorkommt, ist der Punkt ein Tausendertrenner - sonst
            // wäre „3.5" fälschlich 35.
            $raw = str_contains($raw, ',')
                ? str_replace(',', '.', str_replace('.', '', $raw))
                : str_replace([' ', "\u{00a0}", '€'], '', $raw);
        }

        if ($raw === '' || !is_numeric($raw)) {
            throw new ValidationException('Ungültige Zahl.');
        }

        $number = (float) $raw;
        if (abs($number) > 1_000_000_000) {
            throw new ValidationException('Die Zahl ist zu groß.');
        }
        if ($type === LogColumnType::Hours && $number < 0) {
            throw new ValidationException('Stunden können nicht negativ sein.');
        }
        if ($type === LogColumnType::Money || $type === LogColumnType::Hours) {
            $number = round($number, 2);
        }

        return ['text' => null, 'number' => $number, 'lat' => null, 'lon' => null];
    }

    /**
     * Nimmt sowohl vollständige ISO-Zeitstempel mit Zeitzone als auch die
     * Ausgabe eines `datetime-local`-Feldes entgegen und legt UTC ab.
     */
    private function validatedTimestamp(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $time = new \DateTimeImmutable(trim($value));
        } catch (\Throwable) {
            throw new ValidationException('Ungültiger Zeitpunkt.');
        }

        $year = (int) $time->format('Y');
        if ($year < 1900 || $year > 2200) {
            throw new ValidationException('Der Zeitpunkt liegt außerhalb des zulässigen Bereichs.');
        }

        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    private function validatedName(mixed $value): string
    {
        $name = trim(is_scalar($value) ? (string) $value : '');
        if ($name === '' || mb_strlen($name) > 60) {
            throw new ValidationException('Der Spaltenname muss 1-60 Zeichen lang sein.');
        }

        return $name;
    }

    /**
     * Sortierung: `occurred_at` (Vorgabe) oder die ID einer eigenen Spalte.
     * Eine unbekannte Angabe fällt auf den Zeitpunkt zurück, statt zu scheitern.
     *
     * @param array<int, array<string, mixed>> $columns
     */
    private function resolveSortColumn(?string $sort, array $columns): ?int
    {
        if ($sort === null || $sort === '' || $sort === 'occurred_at' || !ctype_digit($sort)) {
            return null;
        }

        $columnId = (int) $sort;
        foreach ($columns as $column) {
            if ((int) $column['id'] === $columnId) {
                return $columnId;
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> Spalten-ID => Spalte */
    private function indexedColumns(int $pageId): array
    {
        $columns = [];
        foreach ($this->log->columnsForPage($pageId) as $column) {
            $columns[(int) $column['id']] = $column;
        }

        return $columns;
    }

    /** @return array<string, mixed> */
    private function requireLogPage(User $user, int $pageId): array
    {
        $page = $this->pages->find($user, $pageId);
        if (($page['type'] ?? null) !== 'log') {
            throw new ValidationException("Seite #{$pageId} ist kein Logbuch.");
        }

        return $page;
    }

    /** @return array<string, mixed> */
    private function requireOwnedColumn(User $user, int $columnId): array
    {
        $column = $this->log->findColumn($columnId);
        if ($column === null) {
            throw new NotFoundException("Spalte #{$columnId} nicht gefunden.");
        }
        $this->requireLogPage($user, (int) $column['page_id']);
        $this->pages->assertCanWrite($user, (int) $column['page_id']);

        return $column;
    }

    /** @return array<string, mixed> */
    private function requireOwnedEntry(User $user, int $entryId): array
    {
        $entry = $this->log->findEntry($entryId);
        if ($entry === null) {
            throw new NotFoundException("Eintrag #{$entryId} nicht gefunden.");
        }
        $this->requireLogPage($user, (int) $entry['page_id']);
        $this->pages->assertCanWrite($user, (int) $entry['page_id']);

        return $entry;
    }
}
