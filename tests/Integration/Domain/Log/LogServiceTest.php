<?php

declare(strict_types=1);

namespace Tests\Integration\Domain\Log;

use App\Domain\Geo\ReverseGeocoder;
use App\Domain\Log\LogService;
use App\Domain\PageService;
use App\Domain\ShareService;
use App\Domain\User;
use App\Repositories\LogRepository;
use App\Repositories\PageRepository;
use App\Repositories\ShareRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\ValidationException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\InMemoryDatabaseTrait;

final class LogServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PDO $pdo;
    private PageService $pages;
    private LogService $log;
    private User $user;
    private int $pageId;

    protected function setUp(): void
    {
        $this->pdo = $this->makeDatabase();
        $workspaces = new WorkspaceRepository($this->pdo);
        $pageRepository = new PageRepository($this->pdo);
        $this->pages = new PageService($pageRepository, $workspaces, new ShareRepository($this->pdo));
        $this->log = new LogService(
            $this->pdo,
            $this->pages,
            $pageRepository,
            new LogRepository($this->pdo),
        );

        $this->user = $this->makeUser($workspaces, 'a@example.com');
        $this->pageId = (int) $this->pages->create($this->user, 'log', 'Fahrtenbuch', null)['id'];
    }

    public function testANewLogStartsWithOneUsableColumn(): void
    {
        $columns = $this->log->columns($this->user, $this->pageId);

        self::assertCount(1, $columns);
        self::assertSame(LogService::DEFAULT_COLUMN_NAME, $columns[0]['name']);
        self::assertSame('text', $columns[0]['type']);
    }

    public function testNewestEntriesComeFirstAndTheOrderCanBeFlipped(): void
    {
        $this->entry('2026-07-01T08:00:00+02:00');
        $this->entry('2026-07-20T08:00:00+02:00');
        $this->entry('2026-07-10T08:00:00+02:00');

        $newestFirst = $this->log->board($this->user, $this->pageId);
        self::assertSame('desc', $newestFirst['direction']);
        self::assertSame(
            ['2026-07-20', '2026-07-10', '2026-07-01'],
            array_map(static fn (array $e): string => substr((string) $e['occurred_at'], 0, 10), $newestFirst['entries']),
        );

        $oldestFirst = $this->log->board($this->user, $this->pageId, 'occurred_at', 'asc');
        self::assertSame(
            ['2026-07-01', '2026-07-10', '2026-07-20'],
            array_map(static fn (array $e): string => substr((string) $e['occurred_at'], 0, 10), $oldestFirst['entries']),
        );
    }

    public function testEntriesCanBeSortedByAnyColumnWithEmptyCellsLast(): void
    {
        $amount = $this->column('Betrag', 'money');
        $this->entry('2026-07-01T08:00:00+02:00', [$amount => '12,50']);
        $this->entry('2026-07-02T08:00:00+02:00', [$amount => '99']);
        $this->entry('2026-07-03T08:00:00+02:00');

        $board = $this->log->board($this->user, $this->pageId, (string) $amount, 'asc');
        $values = array_map(
            static fn (array $entry): ?float => isset($entry['values'][$amount]['value_number'])
                ? (float) $entry['values'][$amount]['value_number']
                : null,
            $board['entries'],
        );

        self::assertSame([12.5, 99.0, null], $values);
        self::assertSame((string) $amount, $board['sort']);
    }

    public function testStoresEveryColumnTypeInItsOwnShape(): void
    {
        $text = $this->column('Tätigkeit', 'text');
        $place = $this->column('Ort', 'location');
        $start = $this->column('Beginn', 'time');
        $hours = $this->column('Dauer', 'hours');
        $count = $this->column('Menge', 'number');
        $money = $this->column('Kosten', 'money');

        $entry = $this->log->createEntry($this->user, $this->pageId, '2026-07-29T09:30:00+02:00', [
            $text => '  Kundentermin  ',
            $place => ['label' => 'Stuttgart', 'lat' => 48.775846, 'lon' => 9.182932],
            $start => '09:30',
            $hours => '2,75',
            $count => 3,
            // Deutsch getippter Betrag mit Tausenderpunkt.
            $money => '1.234,50',
        ]);

        self::assertSame('Kundentermin', $entry['values'][$text]['value_text']);
        self::assertSame('Stuttgart', $entry['values'][$place]['value_text']);
        self::assertSame(48.775846, (float) $entry['values'][$place]['value_lat']);
        self::assertSame('09:30', $entry['values'][$start]['value_text']);
        // Uhrzeiten liegen zusätzlich als Minuten vor, damit sie sortierbar sind.
        self::assertSame(570.0, (float) $entry['values'][$start]['value_number']);
        self::assertSame(2.75, (float) $entry['values'][$hours]['value_number']);
        self::assertSame(3.0, (float) $entry['values'][$count]['value_number']);
        self::assertSame(1234.5, (float) $entry['values'][$money]['value_number']);
        // Der Zeitpunkt liegt in UTC, die Eingabe war Ortszeit (+02:00).
        self::assertSame('2026-07-29T07:30:00.000Z', $entry['occurred_at']);
    }

    public function testUserColumnsShowTheCreatorAndCannotBeOverwritten(): void
    {
        $creator = $this->column('Erstellt von', 'user');

        $entry = $this->entry('2026-07-29T09:00:00+02:00', [$creator => 'Andere Person']);

        self::assertSame('a@example.com', $entry['created_by_name']);
        self::assertArrayNotHasKey($creator, $entry['values']);

        $updated = $this->log->updateEntry($this->user, (int) $entry['id'], [
            'values' => [$creator => 'Noch jemand'],
        ]);
        self::assertSame('a@example.com', $updated['created_by_name']);
        self::assertArrayNotHasKey($creator, $updated['values']);
    }

    public function testEntriesCanBeSortedByTheirCreator(): void
    {
        $creator = $this->column('User', 'user');
        $workspaces = new WorkspaceRepository($this->pdo);
        $secondUser = $this->makeUser($workspaces, 'z@example.com');
        $repository = new LogRepository($this->pdo);

        $repository->createEntry($this->pageId, '2026-07-02T08:00:00.000Z', $secondUser->id);
        $repository->createEntry($this->pageId, '2026-07-01T08:00:00.000Z', $this->user->id);

        $entries = $this->log->board($this->user, $this->pageId, (string) $creator, 'asc')['entries'];

        self::assertSame(
            ['a@example.com', 'z@example.com'],
            array_column($entries, 'created_by_name'),
        );
    }

    public function testPlainDecimalsKeepTheirValue(): void
    {
        $hours = $this->column('Dauer', 'hours');
        $entry = $this->entry('2026-07-29T09:00:00+02:00', [$hours => '3.5']);

        self::assertSame(3.5, (float) $entry['values'][$hours]['value_number']);
    }

    public function testTimeAndValuesOfAnEntryCanBeChangedAndCleared(): void
    {
        $text = $this->column('Notiz', 'text');
        $entry = $this->entry('2026-07-01T08:00:00+02:00', [$text => 'Erst so']);

        $updated = $this->log->updateEntry($this->user, (int) $entry['id'], [
            'occurred_at' => '2026-06-15T14:45:00+02:00',
            'values' => [$text => 'Dann so'],
        ]);
        self::assertSame('2026-06-15T12:45:00.000Z', $updated['occurred_at']);
        self::assertSame('Dann so', $updated['values'][$text]['value_text']);

        $cleared = $this->log->updateEntry($this->user, (int) $entry['id'], ['values' => [$text => '']]);
        self::assertSame([], $cleared['values']);
    }

    public function testEntriesWithoutATimeUseTheMomentOfRecording(): void
    {
        $entry = $this->log->createEntry($this->user, $this->pageId, null, []);

        self::assertNotSame('', $entry['occurred_at']);
        self::assertSame(gmdate('Y-m-d'), substr((string) $entry['occurred_at'], 0, 10));
    }

    public function testDeletingAnEntryRemovesItsValues(): void
    {
        $text = $this->column('Notiz', 'text');
        $entry = $this->entry('2026-07-01T08:00:00+02:00', [$text => 'Weg damit']);

        $this->log->deleteEntry($this->user, (int) $entry['id']);

        self::assertSame([], $this->log->board($this->user, $this->pageId)['entries']);
        self::assertSame(0, $this->countValues());
    }

    public function testColumnsCanBeAddedRenamedMovedAndDeleted(): void
    {
        $first = $this->column('Ort', 'location');
        $second = $this->column('Dauer', 'hours');

        $this->log->updateColumn($this->user, $first, ['name' => 'Einsatzort']);
        $columns = $this->log->moveColumn($this->user, $second, 'up');

        self::assertSame(
            ['Dauer', 'Einsatzort'],
            array_slice(array_map(static fn (array $c): string => (string) $c['name'], $columns), 1),
        );

        $this->log->deleteColumn($this->user, $second);
        self::assertCount(2, $this->log->columns($this->user, $this->pageId));
    }

    public function testDeletingAColumnRemovesItsValuesButKeepsTheEntries(): void
    {
        $column = $this->column('Kosten', 'money');
        $this->entry('2026-07-01T08:00:00+02:00', [$column => '10']);

        $this->log->deleteColumn($this->user, $column);

        self::assertCount(1, $this->log->board($this->user, $this->pageId)['entries']);
        self::assertSame(0, $this->countValues());
    }

    public function testRejectsValuesThatDoNotFitTheirColumn(): void
    {
        $time = $this->column('Beginn', 'time');
        $hours = $this->column('Dauer', 'hours');

        $this->expectValidationError(fn () => $this->entry('2026-07-01T08:00:00+02:00', [$time => '25:99']));
        $this->expectValidationError(fn () => $this->entry('2026-07-01T08:00:00+02:00', [$hours => 'zwei']));
        $this->expectValidationError(fn () => $this->entry('2026-07-01T08:00:00+02:00', [$hours => '-3']));
        $this->expectValidationError(fn () => $this->entry('2026-07-01T08:00:00+02:00', [99_999 => 'x']));
        $this->expectValidationError(fn () => $this->log->createColumn($this->user, $this->pageId, 'X', 'farbe'));
    }

    public function testLooksUpTheAddressForACoordinateOnlyLocation(): void
    {
        $log = $this->logWithGeocoder('Marienplatz 1, 80331 München');
        $column = (int) $log->createColumn($this->user, $this->pageId, 'Ort', 'location')['id'];

        $entry = $log->createEntry($this->user, $this->pageId, '2026-07-29T09:00:00+02:00', [
            // So sieht der Wert aus, wenn der aktuelle Standort eingesetzt wird.
            $column => ['label' => '48.137400, 11.575000', 'lat' => 48.1374, 'lon' => 11.575],
        ]);

        self::assertSame('Marienplatz 1, 80331 München', $entry['values'][$column]['value_text']);
        // Die Koordinaten bleiben daneben erhalten - für den Kartenlink.
        self::assertSame(48.1374, (float) $entry['values'][$column]['value_lat']);
    }

    public function testAnOwnPlaceNameIsNotReplacedByTheAddress(): void
    {
        $log = $this->logWithGeocoder('Marienplatz 1, 80331 München');
        $column = (int) $log->createColumn($this->user, $this->pageId, 'Ort', 'location')['id'];

        $entry = $log->createEntry($this->user, $this->pageId, '2026-07-29T09:00:00+02:00', [
            $column => ['label' => 'Baustelle Nord', 'lat' => 48.1374, 'lon' => 11.575],
        ]);

        self::assertSame('Baustelle Nord', $entry['values'][$column]['value_text']);
        self::assertSame(48.1374, (float) $entry['values'][$column]['value_lat']);
    }

    public function testWithoutAnAddressTheCoordinatesRemainTheLabel(): void
    {
        // Adresssuche abgeschaltet: Der Ort geht dadurch nicht verloren.
        $column = (int) $this->log->createColumn($this->user, $this->pageId, 'Ort', 'location')['id'];

        $entry = $this->entry('2026-07-29T09:00:00+02:00', [
            $column => ['label' => '48.137400, 11.575000', 'lat' => 48.1374, 'lon' => 11.575],
        ]);

        self::assertSame('48.137400, 11.575000', $entry['values'][$column]['value_text']);
    }

    private function logWithGeocoder(string $address): LogService
    {
        $geocoder = new ReverseGeocoder(
            new NullLogger(),
            ReverseGeocoder::DEFAULT_URL,
            'https://notes.example.com',
            'de',
            new Client([
                'handler' => HandlerStack::create(new MockHandler([
                    new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                        'display_name' => $address,
                    ])),
                ])),
                'http_errors' => false,
            ]),
        );

        return new LogService(
            $this->pdo,
            $this->pages,
            new PageRepository($this->pdo),
            new LogRepository($this->pdo),
            $geocoder,
        );
    }

    public function testLogsCanBeSharedJustLikeTaskLists(): void
    {
        $shares = new ShareService($this->pages, new ShareRepository($this->pdo));

        $share = $shares->create($this->user, $this->pageId, 'read');

        self::assertSame('read', $share['permission']);
        self::assertSame($this->pageId, $share['page_id']);
    }

    public function testOtherPageTypesAreNotLogs(): void
    {
        $note = $this->pages->create($this->user, 'note', 'Keine Liste', null);

        $this->expectException(ValidationException::class);
        $this->log->board($this->user, (int) $note['id']);
    }

    private function countValues(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM log_values');
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }

    private function expectValidationError(callable $action): void
    {
        try {
            $action();
            self::fail('Erwartet wurde eine ValidationException.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    private function column(string $name, string $type): int
    {
        return (int) $this->log->createColumn($this->user, $this->pageId, $name, $type)['id'];
    }

    /**
     * @param array<int, mixed> $values
     * @return array<string, mixed>
     */
    private function entry(string $occurredAt, array $values = []): array
    {
        return $this->log->createEntry($this->user, $this->pageId, $occurredAt, $values);
    }

    private function makeUser(WorkspaceRepository $workspaces, string $email): User
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (google_sub, email, name, created_at) VALUES (:sub, :email, :name, :now)'
        );
        $stmt->execute([
            'sub' => $email,
            'email' => $email,
            'name' => $email,
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $workspaces->createForUser($id);

        return new User($id, $email, $email, $email, null, true, false);
    }
}
