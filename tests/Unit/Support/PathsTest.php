<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Paths;
use PHPUnit\Framework\TestCase;

final class PathsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('APP_DATA_DIR');
        putenv('UPLOAD_PATH');
        putenv('LOG_FILE');
    }

    public function testWithoutDataDirPathsStayBelowTheProjectRoot(): void
    {
        putenv('APP_DATA_DIR');

        self::assertSame(Paths::root(), Paths::dataDir());
        self::assertSame(Paths::root() . '/var/uploads', Paths::require('UPLOAD_PATH'));
    }

    public function testRelativePathsResolveAgainstTheDataDir(): void
    {
        putenv('APP_DATA_DIR=/data/');
        putenv('UPLOAD_PATH=var/uploads');

        self::assertSame('/data', Paths::dataDir());
        self::assertSame('/data/var/uploads', Paths::require('UPLOAD_PATH'));
        self::assertSame('/data/var/backups', Paths::require('BACKUP_PATH'));
        self::assertSame('/srv/elsewhere', Paths::data('/srv/elsewhere/'));
        self::assertSame(':memory:', Paths::data(':memory:'));
    }

    public function testEmptyLogFileDisablesTheFile(): void
    {
        putenv('LOG_FILE=');

        self::assertNull(Paths::fromEnv('LOG_FILE'));
    }

    public function testEnsureDirectoriesCreatesMissingFolders(): void
    {
        $dir = sys_get_temp_dir() . '/paths-test-' . bin2hex(random_bytes(6));
        putenv('APP_DATA_DIR=' . $dir);

        Paths::ensureDirectories();

        self::assertDirectoryExists($dir . '/var/uploads');
        self::assertDirectoryExists($dir . '/var/tmp/import');
        self::assertDirectoryExists($dir . '/var/log');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            rmdir($item->getPathname());
        }
        rmdir($dir);
    }
}
