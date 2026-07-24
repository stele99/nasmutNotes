<?php

declare(strict_types=1);

namespace Tests\Integration\Domain\Auth;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\GoogleClaims;
use App\Domain\Auth\InviteInvalidException;
use App\Domain\Auth\NoInviteException;
use App\Repositories\InviteRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\AdminEmails;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryDatabaseTrait;

final class AuthServiceTest extends TestCase
{
    use InMemoryDatabaseTrait;

    private AuthService $auth;
    private UserRepository $users;
    private WorkspaceRepository $workspaces;
    private InviteRepository $invites;

    protected function setUp(): void
    {
        $pdo = $this->makeDatabase();
        $adminEmails = new AdminEmails('admin@example.com');
        $this->users = new UserRepository($pdo, $adminEmails);
        $this->workspaces = new WorkspaceRepository($pdo);
        $this->invites = new InviteRepository($pdo);
        $this->auth = new AuthService($pdo, $this->users, $this->workspaces, $this->invites, $adminEmails);
    }

    private function claims(string $sub, string $email): GoogleClaims
    {
        return new GoogleClaims($sub, $email, true, 'Test User', null, null);
    }

    public function testAdminBootstrapCreatesUserAndWorkspaceWithoutInvite(): void
    {
        $user = $this->auth->loginOrRegister($this->claims('sub-admin', 'admin@example.com'), null);

        self::assertTrue($user->isAdmin);
        self::assertNotNull($this->workspaces->findByUserId($user->id));
    }

    public function testNonAdminWithoutInviteIsRejectedAndNoUserCreated(): void
    {
        $this->expectException(NoInviteException::class);

        try {
            $this->auth->loginOrRegister($this->claims('sub-nobody', 'nobody@example.com'), null);
        } finally {
            self::assertNull($this->users->findByGoogleSub('sub-nobody'));
        }
    }

    public function testValidInviteAllowsRegistrationAndConsumesInvite(): void
    {
        $admin = $this->auth->loginOrRegister($this->claims('sub-admin', 'admin@example.com'), null);
        $rawToken = 'raw-invite-token';
        $this->invites->create(hash('sha256', $rawToken), null, null, $admin->id, 1, $this->future());

        $user = $this->auth->loginOrRegister($this->claims('sub-invited', 'invited@example.com'), $rawToken);

        self::assertFalse($user->isAdmin);
        $invite = $this->invites->findByTokenHash(hash('sha256', $rawToken));
        self::assertNotNull($invite);
        self::assertSame(1, (int) $invite['used_count']);
        self::assertSame('used', InviteRepository::status($invite));
    }

    public function testExhaustedInviteCannotBeReused(): void
    {
        $admin = $this->auth->loginOrRegister($this->claims('sub-admin', 'admin@example.com'), null);
        $rawToken = 'raw-invite-token';
        $this->invites->create(hash('sha256', $rawToken), null, null, $admin->id, 1, $this->future());
        $this->auth->loginOrRegister($this->claims('sub-first', 'first@example.com'), $rawToken);

        $this->expectException(InviteInvalidException::class);

        try {
            $this->auth->loginOrRegister($this->claims('sub-second', 'second@example.com'), $rawToken);
        } finally {
            self::assertNull($this->users->findByGoogleSub('sub-second'));
        }
    }

    public function testEmailRestrictedInviteRejectsMismatch(): void
    {
        $admin = $this->auth->loginOrRegister($this->claims('sub-admin', 'admin@example.com'), null);
        $rawToken = 'raw-invite-token';
        $this->invites->create(hash('sha256', $rawToken), 'onlyfor@example.com', null, $admin->id, 1, $this->future());

        $this->expectException(InviteInvalidException::class);
        $this->auth->loginOrRegister($this->claims('sub-wrong', 'wrong@example.com'), $rawToken);
    }

    public function testExistingUserLogsInAndEmailUpdatesOnChange(): void
    {
        $user = $this->auth->loginOrRegister($this->claims('sub-admin', 'admin@example.com'), null);
        $again = $this->auth->loginOrRegister($this->claims('sub-admin', 'admin-new@example.com'), null);

        self::assertSame($user->id, $again->id);
        self::assertSame('admin-new@example.com', $again->email);
    }

    private function future(): string
    {
        return gmdate('Y-m-d\TH:i:s.v\Z', time() + 86400);
    }
}
