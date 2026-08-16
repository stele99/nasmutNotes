<?php

declare(strict_types=1);

namespace Tests\Integration\Domain\Log;

use App\Domain\Log\LogExportService;
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
use ZipArchive;

final class LogExportServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PDO $pdo;
    private PageService $pages;
    private LogService $log;
    private LogExportService $export;
    private User $user;
    private int $pageId;

    protected function setUp(): void
    {
        $this->pdo = $this->makeDatabase();
        $workspaces = new WorkspaceRepository($this->pdo);
        $pageRepository = new PageRepository($this->pdo);
        $this->pages = new PageService($pageRepository, $workspaces, new ShareRepository($this->pdo));
        $this->log = new LogService($this->pdo, $this->pages, $pageRepository, new LogRepository($this->pdo));
        $this->export = new LogExportService($this->log, $this->pages);

        $this->user = $this->makeUser($workspaces, 'a@example.com');
        $this->pageId = (int) $this->pages->create($this->user, 'log', 'Fahrtenbuch', null)['id'];
    }

    public function testCsvCarriesHeaderRowAndPlainNumbers(): void
    {
        $strecke = $this->column('Strecke', 'text');
        $stunden = $this->column('Stunden', 'hours');
        $this->entry('2026-07-01T08:30:00+02:00', [$strecke => 'Ulm - Stuttgart', $stunden => '1,5']);

        $csv = $this->export->export($this->user, $this->pageId, 'csv', 'Europe/Berlin')['body'];

        self::assertStringStartsWith("\u{FEFF}", $csv, 'Ohne BOM zeigt Excel Umlaute falsch an.');
        // Geprüft werden die Werte, nicht die Schreibweise: Wann PHP ein Feld in
        // Anführungszeichen setzt, ist Sache der Fassung und beides gültiges CSV.
        $rows = array_map(
            static fn (string $line): array => str_getcsv($line, ',', '"', '\\'),
            array_values(array_filter(explode("\n", trim(substr($csv, 3))))),
        );
        // „Eintrag" ist die Vorgabespalte, mit der jedes Logbuch anfängt.
        self::assertSame(['Zeitpunkt', 'Eintrag', 'Strecke', 'Stunden'], $rows[0]);
        // Punkt als Dezimaltrennzeichen, damit jedes Programm die Zahl liest.
        self::assertSame(['2026-07-01 08:30', '', 'Ulm - Stuttgart', '1.5'], $rows[1]);
    }

    public function testTimestampsFollowTheRequestedTimeZone(): void
    {
        $this->entry('2026-07-01T08:30:00+02:00');

        $berlin = $this->export->export($this->user, $this->pageId, 'csv', 'Europe/Berlin')['body'];
        $utc = $this->export->export($this->user, $this->pageId, 'csv', null)['body'];

        self::assertStringContainsString('2026-07-01 08:30', $berlin);
        self::assertStringContainsString('2026-07-01 06:30', $utc);
    }

    public function testAnUnknownTimeZoneFallsBackInsteadOfFailing(): void
    {
        $this->entry('2026-07-01T08:30:00+02:00');

        $csv = $this->export->export($this->user, $this->pageId, 'csv', 'Mars/Olympus')['body'];

        self::assertStringContainsString('2026-07-01 06:30', $csv);
    }

    public function testEntriesAreExportedOldestFirst(): void
    {
        $was = $this->column('Was', 'text');
        $this->entry('2026-07-20T08:00:00+02:00', [$was => 'spaeter']);
        $this->entry('2026-07-01T08:00:00+02:00', [$was => 'frueher']);

        $csv = $this->export->export($this->user, $this->pageId, 'csv', 'Europe/Berlin')['body'];

        self::assertLessThan(
            strpos($csv, 'spaeter'),
            strpos($csv, 'frueher'),
            'Auf Papier und in der Tabelle liest sich ein Logbuch von vorn nach hinten.',
        );
    }

    public function testTheUserColumnShowsWhoWroteTheEntry(): void
    {
        $this->column('Wer', 'user');
        $this->entry('2026-07-01T08:00:00+02:00');

        $csv = $this->export->export($this->user, $this->pageId, 'csv', 'Europe/Berlin')['body'];

        self::assertStringContainsString('a@example.com', $csv);
    }

    public function testRatingsAreExportedAsNumbersNotStars(): void
    {
        $wetter = $this->column('Wetter', 'rating');
        $this->entry('2026-07-01T08:00:00+02:00', [$wetter => '3']);

        $csv = $this->export->export($this->user, $this->pageId, 'csv', 'Europe/Berlin')['body'];

        self::assertStringContainsString(',3', $csv);
        self::assertStringNotContainsString('★', $csv, 'Mit Sternen ließe sich in der Tabelle nicht rechnen.');
    }

    public function testXmlIsWellFormedAndKeepsNumbersMachineReadable(): void
    {
        $ort = $this->column('Ort', 'text');
        $betrag = $this->column('Betrag', 'money');
        $this->entry('2026-07-01T08:30:00+02:00', [$ort => 'Ulm & Neu-Ulm <Mitte>', $betrag => '12,50']);

        $xml = $this->export->export($this->user, $this->pageId, 'xml', 'Europe/Berlin')['body'];

        $document = simplexml_load_string($xml);
        self::assertNotFalse($document, 'Der Export muss gültiges XML sein.');
        self::assertSame('Fahrtenbuch', (string) $document['title']);

        $values = [];
        foreach ($document->entries->entry[0]->value as $value) {
            $values[(string) $value['name']] = $value;
        }
        self::assertSame('Ulm & Neu-Ulm <Mitte>', (string) $values['Ort']);
        self::assertSame('12.5', (string) $values['Betrag']['number']);
        self::assertStringContainsString('2026-07-01T08:30', (string) $document->entries->entry[0]['occurred-at']);
    }

    public function testXlsxIsAZipWithTheExpectedParts(): void
    {
        $strecke = $this->column('Strecke', 'text');
        $stunden = $this->column('Stunden', 'hours');
        $this->entry('2026-07-01T08:30:00+02:00', [$strecke => 'Ulm', $stunden => '1,5']);

        $body = $this->export->export($this->user, $this->pageId, 'xlsx', 'Europe/Berlin')['body'];

        $path = tempnam(sys_get_temp_dir(), 'xlsxtest');
        self::assertNotFalse($path);
        file_put_contents($path, $body);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($path) === true);
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $part) {
            self::assertNotFalse($zip->locateName($part), "Teil fehlt: {$part}");
        }

        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($path);

        self::assertNotFalse(simplexml_load_string($sheet), 'Das Tabellenblatt muss gültiges XML sein.');
        self::assertStringContainsString('<t xml:space="preserve">Strecke</t>', $sheet);
        // Zahl als Zahl, nicht als Text: sonst ließe sich in Excel nicht summieren.
        self::assertStringContainsString('<v>1.5</v>', $sheet);
        // Zeitpunkt als Excel-Datum (Tage seit 1899-12-30), nicht als Zeichenkette.
        self::assertStringContainsString('s="2"><v>46204', $sheet);
    }

    public function testTheSheetNameDropsCharactersExcelRejects(): void
    {
        $pageId = (int) $this->pages->create($this->user, 'log', 'Fahrten [2026] / Q3: alles', null)['id'];

        $body = $this->export->export($this->user, $pageId, 'xlsx', null)['body'];

        $path = tempnam(sys_get_temp_dir(), 'xlsxtest');
        self::assertNotFalse($path);
        file_put_contents($path, $body);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path) === true);
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $zip->close();
        @unlink($path);

        self::assertStringContainsString('name="Fahrten 2026 Q3 alles"', $workbook);
    }

    public function testTheFileNameCarriesTitleAndDate(): void
    {
        $pageId = (int) $this->pages->create($this->user, 'log', 'Übungen im Hörsaal', null)['id'];

        $file = $this->export->export($this->user, $pageId, 'csv', null);

        self::assertSame('uebungen-im-hoersaal-' . gmdate('Y-m-d') . '.csv', $file['filename']);
    }

    public function testAnEmptyLogStillExportsItsHeader(): void
    {
        $this->column('Strecke', 'text');

        $csv = $this->export->export($this->user, $this->pageId, 'csv', null)['body'];

        self::assertStringContainsString('Zeitpunkt,Eintrag,Strecke', $csv);
    }

    public function testAnUnknownFormatIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->export->export($this->user, $this->pageId, 'pdf', null);
    }

    public function testAnotherUsersLogCannotBeExported(): void
    {
        $stranger = $this->makeUser(new WorkspaceRepository($this->pdo), 'z@example.com');

        $this->expectException(\Throwable::class);

        $this->export->export($stranger, $this->pageId, 'csv', null);
    }

    private function column(string $name, string $type): int
    {
        return (int) $this->log->createColumn($this->user, $this->pageId, $name, $type)['id'];
    }

    /** @param array<int, mixed> $values */
    private function entry(string $occurredAt, array $values = []): void
    {
        $this->log->createEntry($this->user, $this->pageId, $occurredAt, $values);
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
