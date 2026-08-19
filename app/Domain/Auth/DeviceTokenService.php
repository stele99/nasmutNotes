<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\DeviceTokenRepository;
use App\Repositories\UserRepository;
use App\Support\NotFoundException;
use App\Support\ValidationException;

/**
 * Automations-Token für nicht-interaktive Zugriffe (NotesVoice, FR-NVOICE).
 * Getrennt vom Session-Cookie und - anders als dieses - nicht universell
 * einsetzbar: Ein Token beweist nur die Identität des Nutzers, welche Routen
 * das akzeptieren, entscheidet allein die Middleware-Verdrahtung in
 * app/Config/routes.php (aktuell ausschließlich POST /api/voice/quick).
 */
final class DeviceTokenService
{
    private const MAX_LABEL_LENGTH = 60;

    /** Genug für mehrere Geräte, ohne dass verwaiste Token unbemerkt wachsen. */
    private const MAX_TOKENS_PER_USER = 20;

    public function __construct(
        private readonly DeviceTokenRepository $tokens,
        private readonly UserRepository $users,
        private readonly AuditLogRepository $auditLog,
    ) {
    }

    /**
     * Erzeugt einen neuen Token und liefert den Rohwert - er wird nirgends
     * gespeichert und ist danach nicht mehr abrufbar.
     *
     * @return array{id: int, label: string, token: string, created_at: string}
     */
    public function issue(User $user, string $label, string $ipHash): array
    {
        $label = trim($label);
        if ($label === '') {
            throw new ValidationException('Bitte einen Namen für das Gerät angeben.');
        }
        if (mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            throw new ValidationException('Der Name darf höchstens ' . self::MAX_LABEL_LENGTH . ' Zeichen lang sein.');
        }
        if (count($this->tokens->allActiveForUser($user->id)) >= self::MAX_TOKENS_PER_USER) {
            throw new ValidationException(
                'Es sind bereits ' . self::MAX_TOKENS_PER_USER . ' Automations-Token angelegt.'
                . ' Bitte zuerst einen nicht mehr benötigten widerrufen.',
            );
        }

        $rawToken = bin2hex(random_bytes(32));
        $createdAt = gmdate('Y-m-d\TH:i:s.v\Z');
        $id = $this->tokens->create($user->id, $label, hash('sha256', $rawToken));

        $this->auditLog->log($user->id, 'device_token_issued', 'device_token', $id, $ipHash, ['label' => $label]);

        return ['id' => $id, 'label' => $label, 'token' => $rawToken, 'created_at' => $createdAt];
    }

    /** @return array<int, array<string, mixed>> */
    public function listFor(User $user): array
    {
        return array_map([$this, 'serialize'], $this->tokens->allActiveForUser($user->id));
    }

    public function revoke(User $user, int $id, string $ipHash): void
    {
        $token = $this->tokens->findById($id);
        if ($token === null || (int) $token['user_id'] !== $user->id) {
            throw new NotFoundException('Automations-Token nicht gefunden.');
        }

        $this->tokens->revoke($id);
        $this->auditLog->log($user->id, 'device_token_revoked', 'device_token', $id, $ipHash);
    }

    /** Löst einen rohen Bearer-Token zum Nutzer auf; aktualisiert last_used_at. */
    public function resolveUser(string $rawToken): ?User
    {
        $token = $this->tokens->findActiveByHash(hash('sha256', $rawToken));
        if ($token === null) {
            return null;
        }

        $user = $this->users->findById((int) $token['user_id']);
        if ($user === null || !$user->isActive) {
            return null;
        }

        $this->tokens->touchLastUsed((int) $token['id']);

        return $user;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serialize(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'label' => (string) $row['label'],
            'created_at' => $row['created_at'],
            'last_used_at' => $row['last_used_at'],
        ];
    }
}
