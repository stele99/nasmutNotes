<?php

declare(strict_types=1);

namespace Tests\Integration\Domain;

use App\Domain\Geo\ReverseGeocoder;
use App\Domain\PageService;
use App\Domain\User;
use App\Repositories\PageRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\ForbiddenException;
use App\Support\NotFoundException;
use App\Support\ValidationException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\InMemoryDatabaseTrait;

final class PageServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private \PDO $pdo;
    private PageService $pages;
    private User $userA;
    private User $userB;

    protected function setUp(): void
    {
        $pdo = $this->makeDatabase();
        $this->pdo = $pdo;
        $workspaces = new WorkspaceRepository($pdo);
        $pages = new PageRepository($pdo);
        $this->pages = new PageService($pages, $workspaces);

        $this->userA = $this->makeUser($pdo, $workspaces, 'a@example.com');
        $this->userB = $this->makeUser($pdo, $workspaces, 'b@example.com');
    }

    private function makeUser(\PDO $pdo, WorkspaceRepository $workspaces, string $email): User
    {
        $stmt = $pdo->prepare('INSERT INTO users (google_sub, email, name, created_at) VALUES (:sub, :email, :name, :now)');
        $stmt->execute(['sub' => $email, 'email' => $email, 'name' => $email, 'now' => gmdate('Y-m-d\TH:i:s.v\Z')]);
        $id = (int) $pdo->lastInsertId();
        $workspaces->createForUser($id);

        return new User($id, $email, $email, $email, null, true, false);
    }

    public function testListAddsNotePreviewAndLastEditor(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Meine Notiz', null);
        $statement = $this->pdo->prepare(
            'UPDATE note_contents SET content_text = :text, updated_by = :user WHERE page_id = :page'
        );
        $statement->execute([
            'text' => "   \n\nErste sichtbare Zeile\nZweite Zeile",
            'user' => $this->userB->id,
            'page' => (int) $page['id'],
        ]);

        $listed = $this->pages->list($this->userA, 'updated', null, false)[0];

        self::assertSame('Erste sichtbare Zeile', $listed['preview']);
        self::assertSame($this->userB->name, $listed['last_editor_name']);
        self::assertNull($listed['task_count']);
    }

    public function testNotePreviewIsShortenedForLongLines(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Lange Notiz', null);
        $statement = $this->pdo->prepare('UPDATE note_contents SET content_text = :text WHERE page_id = :page');
        $statement->execute(['text' => str_repeat('a', 300), 'page' => (int) $page['id']]);

        $listed = $this->pages->list($this->userA, 'updated', null, false)[0];

        self::assertSame(141, mb_strlen((string) $listed['preview']));
        self::assertStringEndsWith('…', (string) $listed['preview']);
    }

    public function testListCountsTasksForTaskPages(): void
    {
        $page = $this->pages->create($this->userA, 'task', 'Mein Board', null);
        $categoryStatement = $this->pdo->prepare(
            'INSERT INTO categories (page_id, name, position, created_at) VALUES (:page, :name, 0, :now)'
        );
        $categoryStatement->execute(['page' => (int) $page['id'], 'name' => 'Offen', 'now' => gmdate('Y-m-d\TH:i:s.v\Z')]);
        $categoryId = (int) $this->pdo->lastInsertId();

        $statement = $this->pdo->prepare(
            'INSERT INTO tasks (category_id, title, is_done, position) VALUES (:category, :title, :done, :position)'
        );
        foreach ([['Offen A', 0], ['Offen B', 0], ['Erledigt', 1]] as $position => [$title, $done]) {
            $statement->execute([
                'category' => (int) $categoryId,
                'title' => $title,
                'done' => $done,
                'position' => $position,
            ]);
        }

        $listed = $this->pages->list($this->userA, 'updated', null, false)[0];

        self::assertSame(3, $listed['task_count']);
        self::assertSame(2, $listed['open_task_count']);
        self::assertNull($listed['preview']);
    }

    public function testCreateNotePageAutoCreatesNoteContentRow(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Meine Notiz', null);

        self::assertSame('note', $page['type']);
        self::assertSame('Meine Notiz', $page['title']);
    }

    public function testCreateTaskPageStartsWithoutCategories(): void
    {
        $page = $this->pages->create($this->userA, 'task', 'Mein Board', null);

        self::assertSame('task', $page['type']);
        $categoryQuery = $this->pdo->prepare('SELECT COUNT(*) FROM categories WHERE page_id = :page');
        $categoryQuery->execute(['page' => (int) $page['id']]);
        self::assertSame(0, (int) $categoryQuery->fetchColumn());
    }

    public function testTitleValidationRejectsEmptyAndTooLong(): void
    {
        $this->expectException(ValidationException::class);
        $this->pages->create($this->userA, 'note', '   ', null);
    }

    public function testTitleValidationRejectsOver200Chars(): void
    {
        $this->expectException(ValidationException::class);
        $this->pages->create($this->userA, 'note', str_repeat('x', 201), null);
    }

    public function testInvalidTypeRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->pages->create($this->userA, 'bogus', 'Title', null);
    }

    public function testCrossUserAccessReturnsNotFound(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Privat', null);

        $this->expectException(NotFoundException::class);
        $this->pages->find($this->userB, (int) $page['id']);
    }

    public function testSoftDeleteHidesFromDefaultListAndRestoreBringsBack(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Trash Me', null);
        $this->pages->softDelete($this->userA, (int) $page['id']);

        $active = $this->pages->list($this->userA, 'updated', null, false);
        self::assertCount(0, $active);

        $trashed = $this->pages->list($this->userA, 'updated', null, true);
        self::assertCount(1, $trashed);

        $this->pages->restore($this->userA, (int) $page['id']);
        self::assertCount(1, $this->pages->list($this->userA, 'updated', null, false));
    }

    public function testPurgeRemovesPagePermanently(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Gone', null);
        $this->pages->purge($this->userA, (int) $page['id']);

        $this->expectException(NotFoundException::class);
        $this->pages->find($this->userA, (int) $page['id']);
    }

    public function testTrashedPageRemainsReadableButCannotBeEdited(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Nur lesen', null);
        $this->pages->softDelete($this->userA, (int) $page['id']);

        self::assertFalse($this->pages->find($this->userA, (int) $page['id'])['can_edit']);

        $this->expectException(ForbiddenException::class);
        $this->pages->update($this->userA, (int) $page['id'], ['title' => 'Nicht erlaubt']);
    }

    public function testMovesMultiplePagesToTrash(): void
    {
        $first = $this->pages->create($this->userA, 'note', 'Erste', null);
        $second = $this->pages->create($this->userA, 'task', 'Zweite', null);

        $trashed = $this->pages->softDeleteMany(
            $this->userA,
            [(int) $first['id'], (int) $second['id']],
        );

        self::assertSame(2, $trashed);
        self::assertCount(0, $this->pages->list($this->userA, 'updated', null, false));
        self::assertCount(2, $this->pages->list($this->userA, 'updated', null, true));
    }

    public function testUpdateFavoriteAndTitle(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Original', null);
        $updated = $this->pages->update($this->userA, (int) $page['id'], [
            'title' => 'Renamed',
            'is_favorite' => true,
        ]);

        self::assertSame('Renamed', $updated['title']);
        self::assertSame(1, (int) $updated['is_favorite']);
    }

    public function testStoresOptionalLocationWithAccuracyAndTimestamp(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Mit Ort', null, null, [
            'lat' => '48.775846',
            'lon' => '9.182932',
            'accuracy' => '12.5',
        ]);

        self::assertSame(48.775846, (float) $page['location_lat']);
        self::assertSame(9.182932, (float) $page['location_lon']);
        self::assertSame(12.5, (float) $page['location_accuracy']);
        self::assertNotNull($page['location_at']);
    }

    public function testUnusableLocationIsDroppedInsteadOfFailing(): void
    {
        $outOfRange = $this->pages->create($this->userA, 'note', 'Falscher Ort', null, null, [
            'lat' => 95.0,
            'lon' => 9.18,
        ]);
        $incomplete = $this->pages->create($this->userA, 'note', 'Halber Ort', null, null, ['lat' => 48.77]);
        $garbage = $this->pages->create($this->userA, 'note', 'Unsinn', null, null, ['lat' => 'x', 'lon' => 'y']);

        foreach ([$outOfRange, $incomplete, $garbage] as $page) {
            self::assertNull($page['location_lat']);
            self::assertNull($page['location_lon']);
            self::assertNull($page['location_at']);
        }
    }

    public function testLocationIsOmittedWhenNotOffered(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Ohne Ort', null);

        self::assertNull($page['location_lat']);
        self::assertNull($page['location_at']);
    }

    public function testLocationCanBeAddedMovedAndRemovedAfterwards(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Erst ohne Ort', null);
        $pageId = (int) $page['id'];

        $added = $this->pages->update($this->userA, $pageId, [
            'location' => ['lat' => 48.775846, 'lon' => 9.182932, 'accuracy' => 25],
        ]);
        self::assertSame(48.775846, (float) $added['location_lat']);
        self::assertNotNull($added['location_at']);

        $moved = $this->pages->update($this->userA, $pageId, [
            'location' => ['lat' => 52.516275, 'lon' => 13.377704],
        ]);
        self::assertSame(52.516275, (float) $moved['location_lat']);
        self::assertSame(13.377704, (float) $moved['location_lon']);
        // Ein Ort ohne gemeldete Genauigkeit überschreibt die alte Angabe.
        self::assertNull($moved['location_accuracy']);

        $removed = $this->pages->update($this->userA, $pageId, ['location' => null]);
        self::assertNull($removed['location_lat']);
        self::assertNull($removed['location_lon']);
        self::assertNull($removed['location_at']);
    }

    public function testEveryPageTypeCanCarryALocation(): void
    {
        foreach (['note', 'task', 'log'] as $type) {
            $page = $this->pages->create($this->userA, $type, "Seite {$type}", null, null, [
                'lat' => 48.775846,
                'lon' => 9.182932,
            ]);
            self::assertSame(48.775846, (float) $page['location_lat'], $type);

            $moved = $this->pages->update($this->userA, (int) $page['id'], [
                'location' => ['lat' => 52.516275, 'lon' => 13.377704],
            ]);
            self::assertSame(52.516275, (float) $moved['location_lat'], $type);
        }
    }

    public function testAnInvalidLocationIsRejectedOnUpdateInsteadOfBeingDropped(): void
    {
        $page = $this->pages->create($this->userA, 'note', 'Mit Ort', null);

        $this->expectException(ValidationException::class);
        $this->pages->update($this->userA, (int) $page['id'], [
            'location' => ['lat' => 'Stuttgart', 'lon' => 'Mitte'],
        ]);
    }

    public function testStoresTheAddressFoundForTheCoordinates(): void
    {
        $pages = new PageService(
            new PageRepository($this->pdo),
            new WorkspaceRepository($this->pdo),
            null,
            null,
            new ReverseGeocoder(
                new NullLogger(),
                ReverseGeocoder::DEFAULT_URL,
                'https://notes.example.com',
                'de',
                new Client([
                    'handler' => HandlerStack::create(new MockHandler([
                        new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                            'address' => ['road' => 'Marienplatz', 'postcode' => '80331', 'city' => 'München'],
                        ])),
                    ])),
                    'http_errors' => false,
                ]),
            ),
        );

        $page = $pages->create($this->userA, 'note', 'Mit Anschrift', null, null, [
            'lat' => 48.137,
            'lon' => 11.575,
        ]);

        self::assertSame('Marienplatz, 80331 München', $page['location_label']);
    }
}
