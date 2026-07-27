<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Import;

use App\Domain\Import\ArchiveChunkStore;
use App\Support\NotFoundException;
use App\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class ArchiveChunkStoreTest extends TestCase
{
    private const MAX_BYTES = 1024 * 1024;

    private string $basePath;
    private ArchiveChunkStore $store;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/shareinfo-chunks-' . bin2hex(random_bytes(6));
        $this->store = new ArchiveChunkStore($this->basePath);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->basePath . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->basePath);
    }

    public function testAssemblesTheArchiveFromItsParts(): void
    {
        $payload = random_bytes(2500);
        $id = $this->store->begin(7, 'export.zip', strlen($payload), self::MAX_BYTES);

        $first = $this->store->append(7, $id, 0, substr($payload, 0, 1000));
        self::assertSame(1000, $first['received_bytes']);
        self::assertSame(1, $first['next_index']);
        self::assertFalse($first['complete']);

        $this->store->append(7, $id, 1, substr($payload, 1000, 1000));
        $last = $this->store->append(7, $id, 2, substr($payload, 2000));
        self::assertTrue($last['complete']);

        $archive = $this->store->finish(7, $id);
        self::assertSame('export.zip', $archive['file_name']);
        self::assertSame(strlen($payload), $archive['size']);
        self::assertSame($payload, (string) file_get_contents($archive['path']));
    }

    /** Ein Teil außer der Reihe ergäbe stillschweigend ein beschädigtes Archiv. */
    public function testOutOfOrderPartIsRejected(): void
    {
        $id = $this->store->begin(7, 'export.zip', 20, self::MAX_BYTES);

        $this->expectException(ValidationException::class);
        $this->store->append(7, $id, 1, 'zu früh');
    }

    public function testMoreDataThanAnnouncedIsRejectedAndTheSessionIsDropped(): void
    {
        $id = $this->store->begin(7, 'export.zip', 10, self::MAX_BYTES);

        try {
            $this->store->append(7, $id, 0, str_repeat('x', 11));
            self::fail('Zu viele Daten müssen abgewiesen werden.');
        } catch (ValidationException) {
            // Erwartet.
        }

        $this->expectException(NotFoundException::class);
        $this->store->finish(7, $id);
    }

    public function testIncompleteUploadCannotBeFinished(): void
    {
        $id = $this->store->begin(7, 'export.zip', 100, self::MAX_BYTES);
        $this->store->append(7, $id, 0, str_repeat('x', 40));

        $this->expectException(ValidationException::class);
        $this->store->finish(7, $id);
    }

    public function testAnotherUserCannotTouchTheUpload(): void
    {
        $id = $this->store->begin(7, 'export.zip', 10, self::MAX_BYTES);

        $this->expectException(NotFoundException::class);
        $this->store->append(8, $id, 0, 'fremd');
    }

    public function testUnknownIdentifierIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->store->finish(7, str_repeat('a', 32));
    }

    /** Kennungen sind auf Hex begrenzt, damit kein Pfad daraus werden kann. */
    public function testPathLikeIdentifierIsRejected(): void
    {
        $this->expectException(NotFoundException::class);
        $this->store->finish(7, '../../etc/passwd');
    }

    public function testArchiveLargerThanAllowedIsRejectedUpFront(): void
    {
        $this->expectException(ValidationException::class);
        $this->store->begin(7, 'export.zip', self::MAX_BYTES + 1, self::MAX_BYTES);
    }

    public function testEmptyArchiveIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->store->begin(7, 'export.zip', 0, self::MAX_BYTES);
    }

    public function testAbandonRemovesOnlyTheOwnUpload(): void
    {
        $id = $this->store->begin(7, 'export.zip', 10, self::MAX_BYTES);

        $this->store->abandon(8, $id);
        self::assertSame(10, $this->store->finish(7, $this->completed($id))['size']);

        $this->store->abandon(7, $id);
        $this->expectException(NotFoundException::class);
        $this->store->finish(7, $id);
    }

    public function testSweepRemovesExpiredSessions(): void
    {
        $id = $this->store->begin(7, 'export.zip', 10, self::MAX_BYTES);
        $this->ageSession($id, 7 * 3600);

        self::assertSame(1, $this->store->sweep());
        self::assertFileDoesNotExist($this->basePath . '/' . $id . '.part');
    }

    public function testSweepKeepsFreshSessions(): void
    {
        $this->store->begin(7, 'export.zip', 10, self::MAX_BYTES);

        self::assertSame(0, $this->store->sweep());
    }

    /** Vervollständigt den Upload, damit finish() nicht an der Größe scheitert. */
    private function completed(string $id): string
    {
        $this->store->append(7, $id, 0, str_repeat('x', 10));

        return $id;
    }

    private function ageSession(string $id, int $seconds): void
    {
        $path = $this->basePath . '/' . $id . '.json';
        $meta = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($meta);
        $meta['created_at'] = time() - $seconds;
        file_put_contents($path, json_encode($meta, JSON_THROW_ON_ERROR));
    }
}
