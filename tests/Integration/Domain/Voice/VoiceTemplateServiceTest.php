<?php

declare(strict_types=1);

namespace Tests\Integration\Domain\Voice;

use App\Domain\User;
use App\Domain\Voice\VoiceTemplateService;
use App\Repositories\AuditLogRepository;
use App\Repositories\VoiceTemplateRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\NotFoundException;
use App\Support\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDatabaseTrait;

final class VoiceTemplateServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private PDO $pdo;
    private VoiceTemplateService $service;
    private User $user;
    private User $other;

    protected function setUp(): void
    {
        $this->pdo = $this->makeDatabase();
        $this->service = new VoiceTemplateService(
            new VoiceTemplateRepository($this->pdo),
            new AuditLogRepository($this->pdo),
        );
        $this->user = $this->makeUser('a@example.com');
        $this->other = $this->makeUser('b@example.com');
    }

    public function testTheMigrationSeedsOneSelectableGlobalTemplate(): void
    {
        $available = $this->service->listAvailableTo($this->user);

        self::assertCount(1, $available);
        self::assertSame('global', $available[0]['scope']);
        self::assertSame('Standard', $available[0]['name']);
    }

    public function testOwnTemplatesAreVisibleOnlyToTheirOwner(): void
    {
        $this->service->create($this->user, $this->user->id, 'Meine', 'Anweisung', '', 'iphash');

        self::assertCount(1, $this->service->listOwn($this->user));
        self::assertSame([], $this->service->listOwn($this->other));
        // Der Fremde sieht weiterhin nur die globale Vorlage.
        self::assertCount(1, $this->service->listAvailableTo($this->other));
        // Der Eigentümer sieht global + eigene, global zuerst.
        $available = $this->service->listAvailableTo($this->user);
        self::assertSame(['global', 'own'], array_column($available, 'scope'));
    }

    public function testAForeignTemplateCanNeitherBeUpdatedNorDeleted(): void
    {
        $created = $this->service->create($this->user, $this->user->id, 'Meine', 'Anweisung', '', 'iphash');
        $id = (int) $created['id'];

        try {
            $this->service->update($this->other, $this->other->id, $id, 'Geklaut', 'Andere', '', 'iphash');
            self::fail('Eine fremde Vorlage wurde geändert.');
        } catch (NotFoundException) {
        }

        try {
            $this->service->delete($this->other, $this->other->id, $id, 'iphash');
            self::fail('Eine fremde Vorlage wurde gelöscht.');
        } catch (NotFoundException) {
        }

        self::assertSame('Meine', $this->service->listOwn($this->user)[0]['name']);
    }

    /** Eine globale Vorlage ist über den persönlichen Bereich unerreichbar. */
    public function testAUserCannotDeleteAGlobalTemplateThroughTheirOwnScope(): void
    {
        $globalId = (int) $this->service->listGlobal()[0]['id'];

        try {
            $this->service->delete($this->user, $this->user->id, $globalId, 'iphash');
            self::fail('Eine globale Vorlage wurde über den Nutzerbereich gelöscht.');
        } catch (NotFoundException) {
        }

        self::assertCount(1, $this->service->listGlobal());
    }

    public function testAdminCannotReachAPersonalTemplateThroughTheGlobalScope(): void
    {
        $own = (int) $this->service->create($this->user, $this->user->id, 'Meine', 'Anweisung', '', 'iphash')['id'];

        $this->expectException(NotFoundException::class);
        $this->service->delete($this->user, null, $own, 'iphash');
    }

    /**
     * Ohne globale Vorlage könnte niemand mehr diktieren, der sich keine
     * eigene angelegt hat - die letzte ist deshalb geschützt.
     */
    public function testTheLastGlobalTemplateCannotBeDeleted(): void
    {
        $globalId = (int) $this->service->listGlobal()[0]['id'];

        try {
            $this->service->delete($this->user, null, $globalId, 'iphash');
            self::fail('Die letzte globale Vorlage wurde gelöscht.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('letzte globale Vorlage', $e->getMessage());
        }

        // Mit einer zweiten globalen Vorlage ist das Löschen wieder erlaubt.
        $this->service->create($this->user, null, 'Zweite', 'Anweisung', '', 'iphash');
        $this->service->delete($this->user, null, $globalId, 'iphash');

        self::assertCount(1, $this->service->listGlobal());
    }

    public function testPersonalTemplatesCanAlwaysBeDeleted(): void
    {
        $id = (int) $this->service->create($this->user, $this->user->id, 'Meine', 'Anweisung', '', 'iphash')['id'];

        $this->service->delete($this->user, $this->user->id, $id, 'iphash');

        self::assertSame([], $this->service->listOwn($this->user));
    }

    public function testNameAndInstructionAreRequiredAndTrimmed(): void
    {
        foreach ([['', 'Anweisung'], ['   ', 'Anweisung'], ['Name', ''], ['Name', '  ']] as [$name, $instruction]) {
            try {
                $this->service->create($this->user, $this->user->id, $name, $instruction, '', 'iphash');
                self::fail("Leere Eingabe wurde akzeptiert: '{$name}' / '{$instruction}'");
            } catch (ValidationException) {
            }
        }

        $created = $this->service->create($this->user, $this->user->id, '  Name  ', '  Anweisung  ', '  Wort  ', 'iphash');

        self::assertSame('Name', $created['name']);
        self::assertSame('Anweisung', $created['instruction']);
        self::assertSame('Wort', $created['vocabulary']);
    }

    public function testOverlongInputIsRefused(): void
    {
        $cases = [
            [str_repeat('a', 81), 'Anweisung', ''],
            ['Name', str_repeat('a', 8001), ''],
            ['Name', 'Anweisung', str_repeat('a', 601)],
        ];

        foreach ($cases as [$name, $instruction, $vocabulary]) {
            try {
                $this->service->create($this->user, $this->user->id, $name, $instruction, $vocabulary, 'iphash');
                self::fail('Zu lange Eingabe wurde akzeptiert.');
            } catch (ValidationException) {
            }
        }

        self::assertSame([], $this->service->listOwn($this->user));
    }

    /** Die Obergrenze gilt je Bereich, nicht über alle Vorlagen zusammen. */
    public function testTheTemplateLimitIsCountedPerScope(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->service->create($this->user, $this->user->id, "Meine {$i}", 'Anweisung', '', 'iphash');
        }

        try {
            $this->service->create($this->user, $this->user->id, 'Eine zu viel', 'Anweisung', '', 'iphash');
            self::fail('Die Obergrenze wurde nicht durchgesetzt.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('20 Vorlagen', $e->getMessage());
        }

        // Der globale Bereich und andere Nutzer bleiben davon unberührt.
        $this->service->create($this->user, null, 'Global', 'Anweisung', '', 'iphash');
        $this->service->create($this->other, $this->other->id, 'Fremd', 'Anweisung', '', 'iphash');

        self::assertCount(20, $this->service->listOwn($this->user));
        self::assertCount(2, $this->service->listGlobal());
    }

    /** Der handelnde Mensch steht im Audit-Log, auch wenn er global arbeitet. */
    public function testGlobalChangesAreAuditedWithTheActingAdmin(): void
    {
        $this->service->create($this->user, null, 'Global', 'Anweisung', '', 'iphash');

        $stmt = $this->pdo->prepare(
            'SELECT user_id FROM audit_log WHERE action = :action ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['action' => 'voice_template_created']);

        self::assertSame($this->user->id, (int) $stmt->fetchColumn());
    }

    public function testResolveAccessibleAllowsGlobalAndOwnButNotForeign(): void
    {
        $globalId = (int) $this->service->listGlobal()[0]['id'];
        $ownId = (int) $this->service->create($this->user, $this->user->id, 'Meine', 'Anweisung', '', 'iphash')['id'];
        $foreignId = (int) $this->service->create($this->other, $this->other->id, 'Fremd', 'Anweisung', '', 'iphash')['id'];

        self::assertSame('Standard', $this->service->resolveAccessible($this->user, $globalId)['name']);
        self::assertSame('Meine', $this->service->resolveAccessible($this->user, $ownId)['name']);

        $this->expectException(ValidationException::class);
        $this->service->resolveAccessible($this->user, $foreignId);
    }

    private function makeUser(string $email): User
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
        new WorkspaceRepository($this->pdo)->createForUser($id);

        return new User($id, $email, $email, $email, null, true, false);
    }
}
