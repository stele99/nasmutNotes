<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Database;
use App\Support\Migrator;
use PDO;

trait InMemoryDatabaseTrait
{
    private function makeDatabase(): PDO
    {
        $pdo = Database::connect(':memory:');
        $migrator = new Migrator($pdo, dirname(__DIR__, 2) . '/database/migrations');
        $migrator->migrate();

        return $pdo;
    }
}
