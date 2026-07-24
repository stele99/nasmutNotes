<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\User;
use App\Repositories\InviteRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\AdminEmails;
use PDO;

final class AuthService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly UserRepository $users,
        private readonly WorkspaceRepository $workspaces,
        private readonly InviteRepository $invites,
        private readonly AdminEmails $adminEmails,
    ) {
    }

    /**
     * @throws NoInviteException wenn kein Konto existiert und kein gültiges Invite vorliegt (AK-01).
     * @throws InviteInvalidException wenn ein übergebenes Invite-Token ungültig ist.
     */
    public function loginOrRegister(GoogleClaims $claims, ?string $rawInviteToken): User
    {
        $existing = $this->users->findByGoogleSub($claims->sub);
        if ($existing !== null) {
            if ($existing->email !== $claims->email) {
                $this->users->updateEmail($existing->id, $claims->email);
            }
            $this->users->touchLastLogin($existing->id);

            return $this->users->findById($existing->id) ?? $existing;
        }

        if ($this->adminEmails->isAdmin($claims->email)) {
            return $this->createUserWithWorkspace($claims);
        }

        if ($rawInviteToken === null || $rawInviteToken === '') {
            throw new NoInviteException();
        }

        return $this->registerWithInvite($claims, $rawInviteToken);
    }

    private function registerWithInvite(GoogleClaims $claims, string $rawInviteToken): User
    {
        $tokenHash = hash('sha256', $rawInviteToken);
        $invite = $this->invites->findByTokenHash($tokenHash);

        if ($invite === null) {
            throw new InviteInvalidException('nicht gefunden');
        }

        $status = InviteRepository::status($invite);
        if ($status !== 'open') {
            throw new InviteInvalidException($status);
        }

        $targetEmail = $invite['email'];
        if ($targetEmail !== null && $targetEmail !== '' && $targetEmail !== $claims->email) {
            throw new InviteInvalidException('E-Mail stimmt nicht mit dem Invite überein');
        }

        $this->pdo->beginTransaction();
        try {
            $user = $this->createUserWithWorkspace($claims);
            $this->invites->incrementUse((int) $invite['id']);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $user;
    }

    private function createUserWithWorkspace(GoogleClaims $claims): User
    {
        $alreadyInTransaction = $this->pdo->inTransaction();
        if (!$alreadyInTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $user = $this->users->create($claims->sub, $claims->email, $claims->name, $claims->picture);
            $this->workspaces->createForUser($user->id);

            if (!$alreadyInTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if (!$alreadyInTransaction) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $user;
    }
}
