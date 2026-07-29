<?php

declare(strict_types=1);

namespace Tests\Integration\Domain\Geo;

use App\Domain\Geo\NearbySearchService;
use App\Domain\Log\LogService;
use App\Domain\PageService;
use App\Domain\User;
use App\Repositories\LogRepository;
use App\Repositories\PageRepository;
use App\Repositories\ShareRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDatabaseTrait;

final class NearbySearchServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PDO $pdo;
    private PageService $pages;
    private LogService $log;
    private NearbySearchService $nearby;
    private User $user;
    private User $otherUser;

    // Stuttgart-Mitte; die weiteren Punkte liegen bewusst in bekannter Entfernung davon.
    private const STUTTGART_LAT = 48.775846;
    private const STUTTGART_LON = 9.182932;

    protected function setUp(): void
    {
        $this->pdo = $this->makeDatabase();
        $workspaces = new WorkspaceRepository($this->pdo);
        $pageRepository = new PageRepository($this->pdo);
        $logRepository = new LogRepository($this->pdo);
        $this->pages = new PageService($pageRepository, $workspaces, new ShareRepository($this->pdo));
        $this->log = new LogService($this->pdo, $this->pages, $pageRepository, $logRepository);
        $this->nearby = new NearbySearchService($this->pages, $pageRepository, $logRepository);

        $this->user = $this->makeUser($workspaces, 'a@example.com');
        $this->otherUser = $this->makeUser($workspaces, 'b@example.com');
    }

    public function testFindsAPageWithinTheRadiusButNotOneOutsideIt(): void
    {
        $near = $this->pages->create($this->user, 'note', 'Café um die Ecke', null, null, [
            'lat' => self::STUTTGART_LAT,
            // Rund 400 m östlich - deutlich innerhalb 1 km.
            'lon' => self::STUTTGART_LON + 0.006,
        ]);
        $far = $this->pages->create($this->user, 'note', 'Berlin-Notiz', null, null, [
            'lat' => 52.516275,
            'lon' => 13.377704,
        ]);

        $results = $this->nearby->search($this->user, self::STUTTGART_LAT, self::STUTTGART_LON, 1.0);

        $ids = array_column($results, 'page_id');
        self::assertContains((int) $near['id'], $ids);
        self::assertNotContains((int) $far['id'], $ids);
    }

    public function testResultsAreSortedByDistanceAscending(): void
    {
        $far = $this->pages->create($this->user, 'note', 'Weiter weg', null, null, [
            'lat' => self::STUTTGART_LAT + 0.02,
            'lon' => self::STUTTGART_LON,
        ]);
        $near = $this->pages->create($this->user, 'note', 'Ganz nah', null, null, [
            'lat' => self::STUTTGART_LAT + 0.001,
            'lon' => self::STUTTGART_LON,
        ]);

        $results = $this->nearby->search($this->user, self::STUTTGART_LAT, self::STUTTGART_LON, 10.0);

        self::assertSame((int) $near['id'], $results[0]['page_id']);
        self::assertSame((int) $far['id'], $results[1]['page_id']);
        self::assertLessThan($results[1]['distance_km'], $results[0]['distance_km']);
    }

    public function testTaskAndLogPagesAreIncludedAsWellAsNotes(): void
    {
        $task = $this->pages->create($this->user, 'task', 'Baustelle', null, null, [
            'lat' => self::STUTTGART_LAT,
            'lon' => self::STUTTGART_LON,
        ]);
        $log = $this->pages->create($this->user, 'log', 'Fahrtenbuch', null, null, [
            'lat' => self::STUTTGART_LAT,
            'lon' => self::STUTTGART_LON,
        ]);

        $results = $this->nearby->search($this->user, self::STUTTGART_LAT, self::STUTTGART_LON, 0.5);

        $types = array_column($results, 'page_type');
        self::assertContains('task', $types);
        self::assertContains('log', $types);
        self::assertNotEmpty(array_filter($results, static fn (array $r): bool => (int) $r['page_id'] === (int) $log['id']));
        self::assertNotEmpty(array_filter($results, static fn (array $r): bool => (int) $r['page_id'] === (int) $task['id']));
    }

    public function testFindsALocatedLogEntryByItsPageTitle(): void
    {
        $logPage = $this->pages->create($this->user, 'log', 'Einsätze', null);
        $pageId = (int) $logPage['id'];
        $column = (int) $this->log->createColumn($this->user, $pageId, 'Ort', 'location')['id'];

        $this->log->createEntry($this->user, $pageId, '2026-07-29T09:00:00+02:00', [
            $column => ['label' => 'Rathaus', 'lat' => self::STUTTGART_LAT, 'lon' => self::STUTTGART_LON],
        ]);

        $results = $this->nearby->search($this->user, self::STUTTGART_LAT, self::STUTTGART_LON, 0.5);

        self::assertCount(1, $results);
        self::assertSame($pageId, $results[0]['page_id']);
        self::assertSame('log', $results[0]['page_type']);
        self::assertSame('Einsätze', $results[0]['title']);
        self::assertSame('Rathaus', $results[0]['label']);
    }

    /**
     * Kernanforderung: Wir suchen Seiten, nicht einzelne Logbuch-Einträge -
     * ein Logbuch mit mehreren passenden Einträgen erscheint nur einmal, mit
     * der Entfernung zum nächstgelegenen Treffer.
     */
    public function testALogPageWithSeveralMatchingEntriesAppearsOnlyOnce(): void
    {
        $logPage = $this->pages->create($this->user, 'log', 'Fahrtenbuch', null);
        $pageId = (int) $logPage['id'];
        $column = (int) $this->log->createColumn($this->user, $pageId, 'Ort', 'location')['id'];

        // Weiter weg.
        $this->log->createEntry($this->user, $pageId, '2026-07-29T08:00:00+02:00', [
            $column => ['label' => 'Weiter weg', 'lat' => self::STUTTGART_LAT + 0.02, 'lon' => self::STUTTGART_LON],
        ]);
        // Ganz nah - das soll die gemeldete Entfernung bestimmen.
        $this->log->createEntry($this->user, $pageId, '2026-07-29T09:00:00+02:00', [
            $column => ['label' => 'Ganz nah', 'lat' => self::STUTTGART_LAT + 0.001, 'lon' => self::STUTTGART_LON],
        ]);

        $results = $this->nearby->search($this->user, self::STUTTGART_LAT, self::STUTTGART_LON, 10.0);

        self::assertCount(1, $results);
        self::assertSame($pageId, $results[0]['page_id']);
        self::assertSame('Ganz nah', $results[0]['label']);
        self::assertLessThan(0.5, $results[0]['distance_km']);
    }

    /**
     * Hat eine Logbuch-Seite sowohl einen eigenen Ort (FR-NOTE-25) als auch
     * Einträge mit Ortsspalten, zählt für die Meldung der nähere von beiden.
     */
    public function testTheClosestOfAPagesOwnLocationAndItsEntriesWins(): void
    {
        $logPage = $this->pages->create($this->user, 'log', 'Fahrtenbuch', null, null, [
            // Der Seitenort selbst liegt weiter weg.
            'lat' => self::STUTTGART_LAT + 0.02,
            'lon' => self::STUTTGART_LON,
        ]);
        $pageId = (int) $logPage['id'];
        $column = (int) $this->log->createColumn($this->user, $pageId, 'Ort', 'location')['id'];
        $this->log->createEntry($this->user, $pageId, '2026-07-29T09:00:00+02:00', [
            $column => ['label' => 'Naher Eintrag', 'lat' => self::STUTTGART_LAT + 0.001, 'lon' => self::STUTTGART_LON],
        ]);

        $results = $this->nearby->search($this->user, self::STUTTGART_LAT, self::STUTTGART_LON, 10.0);

        self::assertCount(1, $results);
        self::assertSame('Naher Eintrag', $results[0]['label']);
    }

    public function testTextOnlyLocationColumnsAreNotFoundByRadius(): void
    {
        $logPage = $this->pages->create($this->user, 'log', 'Notizen', null);
        $pageId = (int) $logPage['id'];
        $column = (int) $this->log->createColumn($this->user, $pageId, 'Ort', 'location')['id'];
        // Kein Koordinatenpaar - reiner Ortsname (siehe LogService::locationValue()).
        $this->log->createEntry($this->user, $pageId, '2026-07-29T09:00:00+02:00', [
            $column => ['label' => 'Irgendwo'],
        ]);

        self::assertSame([], $this->nearby->search($this->user, self::STUTTGART_LAT, self::STUTTGART_LON, 100.0));
    }

    public function testOtherUsersWorkspaceIsNeverSearched(): void
    {
        $this->pages->create($this->otherUser, 'note', 'Fremde Notiz', null, null, [
            'lat' => self::STUTTGART_LAT,
            'lon' => self::STUTTGART_LON,
        ]);

        self::assertSame([], $this->nearby->search($this->user, self::STUTTGART_LAT, self::STUTTGART_LON, 10.0));
    }

    public function testInvalidCoordinatesAreRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->nearby->search($this->user, 95.0, 9.0, 1.0);
    }

    public function testRadiusIsClampedInsteadOfFailingOnExtremeInput(): void
    {
        $page = $this->pages->create($this->user, 'note', 'Weltweit', null, null, [
            'lat' => self::STUTTGART_LAT,
            'lon' => self::STUTTGART_LON,
        ]);

        // Ein absurd großer Radius wird gedeckelt, liefert aber trotzdem den
        // (nahen) Treffer statt eine Ausnahme zu werfen.
        $results = $this->nearby->search($this->user, self::STUTTGART_LAT, self::STUTTGART_LON, 999_999.0);

        self::assertCount(1, $results);
        self::assertSame((int) $page['id'], $results[0]['page_id']);
    }

    public function testDistanceKmMatchesKnownDistanceBetweenTwoCities(): void
    {
        // Stuttgart-Berlin liegt laut gängigen Referenzen bei rund 511 km Luftlinie.
        $distance = NearbySearchService::distanceKm(
            self::STUTTGART_LAT,
            self::STUTTGART_LON,
            52.516275,
            13.377704,
        );

        self::assertEqualsWithDelta(511, $distance, 5);
    }

    public function testDistanceToItselfIsZero(): void
    {
        self::assertSame(0.0, NearbySearchService::distanceKm(
            self::STUTTGART_LAT,
            self::STUTTGART_LON,
            self::STUTTGART_LAT,
            self::STUTTGART_LON,
        ));
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
