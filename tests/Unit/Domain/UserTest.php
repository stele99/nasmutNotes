<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testTheFirstNameIsTheLeadingPartOfTheFullName(): void
    {
        self::assertSame('Steffen', $this->userNamed('Steffen Epple')->firstName());
        self::assertSame('Anna', $this->userNamed('Anna')->firstName());
        self::assertSame('Maria', $this->userNamed('  Maria Luise  von Something ')->firstName());
    }

    public function testWithoutAUsableNameThereIsNoFirstName(): void
    {
        // Ohne Namen und bei einer E-Mail-Adresse als Name gibt es niemanden,
        // der sich angesprochen fühlt - die Anrede entfällt dann.
        self::assertNull($this->userNamed('')->firstName());
        self::assertNull($this->userNamed('   ')->firstName());
        self::assertNull($this->userNamed('a@example.com')->firstName());
    }

    private function userNamed(string $name): User
    {
        return new User(1, 'sub', 'a@example.com', $name, null, true, false);
    }
}
