<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\AdminEmails;
use PHPUnit\Framework\TestCase;

final class AdminEmailsTest extends TestCase
{
    public function testExactMatch(): void
    {
        $admins = new AdminEmails('chef@example.com,ops@example.com');

        self::assertTrue($admins->isAdmin('chef@example.com'));
        self::assertTrue($admins->isAdmin('ops@example.com'));
        self::assertFalse($admins->isAdmin('someone-else@example.com'));
    }

    public function testCaseInsensitiveAndTrimmed(): void
    {
        $admins = new AdminEmails(' Chef@Example.com , ops@example.com ');

        self::assertTrue($admins->isAdmin('chef@example.com'));
        self::assertTrue($admins->isAdmin('CHEF@EXAMPLE.COM'));
        self::assertTrue($admins->isAdmin('  ops@example.com  '));
    }

    public function testEmptyConfigMeansNoAdmins(): void
    {
        $admins = new AdminEmails('');

        self::assertFalse($admins->isAdmin('anyone@example.com'));
    }
}
