<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use App\Repositories\UserRepository;
use App\Support\AdminEmails;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDatabaseTrait;

final class UserRepositoryTest extends TestCase
{
    use InMemoryDatabaseTrait;

    public function testNearbySearchRadiusHasADefaultAndCanBeUpdated(): void
    {
        $users = new UserRepository($this->makeDatabase(), new AdminEmails(''));
        $user = $users->create('sub-1', 'a@example.com', 'A', null);

        self::assertSame(1.0, $user->nearbySearchRadiusKm);

        $users->updateNearbySearchRadius($user->id, 7.5);

        self::assertSame(7.5, $users->findById($user->id)?->nearbySearchRadiusKm);
    }

    public function testInformationAcknowledgementIsStoredOnTheUser(): void
    {
        $users = new UserRepository($this->makeDatabase(), new AdminEmails(''));
        $user = $users->create('sub-1', 'a@example.com', 'A', null);

        self::assertNull($user->infoAcknowledgedAt);

        $acknowledgedAt = $users->acknowledgeInfo($user->id);

        self::assertNotSame('', $acknowledgedAt);
        self::assertSame($acknowledgedAt, $users->findById($user->id)?->infoAcknowledgedAt);
    }
}
