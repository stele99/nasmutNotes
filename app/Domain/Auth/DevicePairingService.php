<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\DevicePairRequestRepository;
use App\Repositories\UserRepository;
use App\Support\NotFoundException;
use App\Support\ValidationException;

/**
 * Paarung des Desktop-Assistant nach dem Muster von WhatsApp/Nextcloud
 * (RFC-8628-Device-Flow ohne OAuth-Ballast): Der Client startet eine
 * Pairing-Sitzung, öffnet den Browser mit dem kurzen Anzeige-Code, und der
 * angemeldete Nutzer bestätigt die Verbindung. Der API-Token wird erzeugt,
 * sobald der Client mit seinem geheimen device_code abholt - er läuft nie
 * über eine URL.
 *
 * Ablauf:
 *  1. Client  -> POST /api/assistant/pair       (label, client_id, platform)
 *  2. Client  -> Browser öffnet /assistant/pair?code=USER-CODE
 *  3. Nutzer  -> POST /api/assistant/pair/approve  (Session + CSRF)
 *  4. Client  -> POST /api/assistant/pair/poll     (device_code) => Token
 */
final class DevicePairingService
{
    private const CODE_TTL_SECONDS = 600;

    /** Ohne I, O, 0, 1 - verlesen kann niemand etwas, das es nicht gibt. */
    private const USER_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const USER_CODE_LENGTH = 8;

    public function __construct(
        private readonly DevicePairRequestRepository $requests,
        private readonly DeviceTokenService $tokens,
        private readonly UserRepository $users,
        private readonly AuditLogRepository $auditLog,
    ) {
    }

    /**
     * Startet eine Pairing-Sitzung. Eine ältere Sitzung desselben client_id
     * wird verworfen - nur der zuletzt erzeugte Code ist gültig.
     *
     * @return array{user_code: string, device_code: string, expires_in: int}
     */
    public function start(string $label, string $clientId, ?string $platform): array
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 60) {
            throw new ValidationException('Der Gerätename muss 1 bis 60 Zeichen lang sein.');
        }
        if (preg_match('/^[A-Za-z0-9._-]{8,64}$/', $clientId) !== 1) {
            throw new ValidationException('Die Client-Kennung muss 8 bis 64 Zeichen aus A-Z, a-z, 0-9, ".", "_", "-" enthalten.');
        }
        $platform = trim((string) $platform);
        if ($platform !== '' && mb_strlen($platform) > 60) {
            throw new ValidationException('Die Plattform-Angabe darf höchstens 60 Zeichen lang sein.');
        }

        $now = gmdate('Y-m-d\TH:i:s.v\Z');
        $this->requests->deleteExpired($now);
        $this->requests->deleteForClient($clientId);

        $userCode = $this->generateUserCode();
        $deviceCode = bin2hex(random_bytes(32));

        $this->requests->create(
            // Der Hash wird über die normalisierte Form (ohne Bindestrich)
            // gebildet, genau wie bei approve und describeByUserCode.
            hash('sha256', $this->normalizeUserCode($userCode)),
            hash('sha256', $deviceCode),
            $clientId,
            $label,
            $platform !== '' ? $platform : null,
            $now,
            gmdate('Y-m-d\TH:i:s.v\Z', time() + self::CODE_TTL_SECONDS),
        );

        $this->auditLog->log(null, 'device_pair_started', null, null, null, [
            'client_id' => $clientId,
            'label' => $label,
        ]);

        return ['user_code' => $userCode, 'device_code' => $deviceCode, 'expires_in' => self::CODE_TTL_SECONDS];
    }

    /**
     * Anzeige-Daten für die Bestätigungsseite; null heißt unbekannt oder
     * abgelaufen. Die Seite zeigt nur Namen und Plattform, nie den Code des
     * Clients.
     *
     * @return array{label: string, platform: ?string, expires_in: int}|null
     */
    public function describeByUserCode(string $userCode): ?array
    {
        $request = $this->requests->findByUserCodeHash(hash('sha256', $this->normalizeUserCode($userCode)));
        if ($request === null || $this->isExpired($request)) {
            return null;
        }

        return [
            'label' => (string) $request['label'],
            'platform' => $request['platform'] !== null ? (string) $request['platform'] : null,
            'expires_in' => max(0, strtotime((string) $request['expires_at']) - time()),
        ];
    }

    /**
     * Bestätigung durch den angemeldeten Nutzer. Mehrfach-Bestätigung wird
     * abgelehnt, damit ein gezeigter Code nicht nachträglich umbiegt.
     */
    public function approve(User $user, string $userCode, string $ipHash): void
    {
        $request = $this->requests->findByUserCodeHash(hash('sha256', $this->normalizeUserCode($userCode)));
        if ($request === null) {
            throw new NotFoundException('Dieser Verbindungsgcode ist unbekannt.');
        }
        if ($this->isExpired($request)) {
            $this->requests->delete((int) $request['id']);
            throw new ValidationException('Dieser Verbindungsgcode ist abgelaufen. Bitte im Desktop-Assistenten neu starten.');
        }
        if ($request['approved_user_id'] !== null) {
            throw new ValidationException('Dieser Verbindungsgcode wurde bereits bestätigt.');
        }

        $this->requests->markApproved(
            (int) $request['id'],
            $user->id,
            gmdate('Y-m-d\TH:i:s.v\Z'),
        );

        $this->auditLog->log($user->id, 'device_pair_approved', 'device_pair_request', (int) $request['id'], $ipHash, [
            'label' => (string) $request['label'],
            'client_id' => (string) $request['client_id'],
        ]);
    }

    /**
     * Abholung durch den Client: solange "pending", nach Bestätigung genau
     * einmal der API-Token samt Nutzerdaten. Ein verbrauchter oder
     * abgelaufener Code endet als "expired" - dann startet der Client neu.
     *
     * @return array{status: string, expires_in?: int, token?: string, user?: array{name: string, email: string}}
     */
    public function poll(string $deviceCode): array
    {
        $request = $this->requests->findByDeviceCodeHash(hash('sha256', trim($deviceCode)));
        if ($request === null || $request['consumed_at'] !== null) {
            return ['status' => 'expired'];
        }
        if ($this->isExpired($request)) {
            $this->requests->delete((int) $request['id']);

            return ['status' => 'expired'];
        }
        if ($request['approved_user_id'] === null) {
            return ['status' => 'pending', 'expires_in' => max(0, strtotime((string) $request['expires_at']) - time())];
        }

        $user = $this->users->findById((int) $request['approved_user_id']);
        if ($user === null || !$user->isActive) {
            $this->requests->delete((int) $request['id']);

            return ['status' => 'expired'];
        }

        $issued = $this->tokens->issuePaired(
            $user,
            (string) $request['label'],
            (string) $request['client_id'],
            $request['platform'] !== null ? (string) $request['platform'] : null,
            '',
        );

        $now = gmdate('Y-m-d\TH:i:s.v\Z');
        $this->requests->markConsumed((int) $request['id'], $issued['id'], $now);
        $this->auditLog->log($user->id, 'device_pair_completed', 'device_pair_request', (int) $request['id'], null, [
            'label' => (string) $request['label'],
        ]);

        return [
            'status' => 'approved',
            'token' => $issued['token'],
            'user' => ['name' => $user->name, 'email' => $user->email],
        ];
    }

    private function generateUserCode(): string
    {
        $alphabetLength = mb_strlen(self::USER_CODE_ALPHABET);
        $code = '';
        for ($i = 0; $i < self::USER_CODE_LENGTH; ++$i) {
            $code .= self::USER_CODE_ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return substr($code, 0, 4) . '-' . substr($code, 4);
    }

    private function normalizeUserCode(string $userCode): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $userCode) ?? '');
    }

    /** @param array<string, mixed> $request */
    private function isExpired(array $request): bool
    {
        return strtotime((string) $request['expires_at']) <= time();
    }
}
