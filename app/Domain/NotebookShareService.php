<?php

declare(strict_types=1);

namespace App\Domain;

use App\Repositories\NotebookRepository;
use App\Repositories\NotebookShareRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkspaceRepository;
use App\Support\NotFoundException;
use App\Support\ValidationException;

/**
 * Notizbücher mit registrierten Nutzern teilen (per E-Mail). Der Eigentümer
 * fügt Teilnehmer hinzu und entfernt sie, Teilnehmer können die Freigabe
 * selbst verlassen. Alle Beteiligten dürfen sämtliche Seiten des Notizbuchs
 * bearbeiten; das Notizbuch samt Seiten bleibt beim Eigentümer.
 */
final class NotebookShareService
{
    public function __construct(
        private readonly NotebookRepository $notebooks,
        private readonly NotebookShareRepository $shares,
        private readonly UserRepository $users,
        private readonly WorkspaceRepository $workspaces,
    ) {
    }

    /**
     * @return array{id: int, name: string, email: string, created_at: string}
     */
    public function share(User $owner, int $notebookId, string $email): array
    {
        $notebook = $this->requireOwned($owner, $notebookId);
        $email = trim($email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException('Ungültige E-Mail-Adresse.');
        }

        $participant = $this->users->findByEmail($email);
        if ($participant === null) {
            throw new ValidationException(
                'Kein registrierter Nutzer mit dieser E-Mail-Adresse gefunden. '
                . 'Es können nur registrierte Nasmut-Notes-Nutzer hinzugefügt werden.',
            );
        }
        if (!$participant->isActive) {
            throw new ValidationException('Dieser Nutzer ist deaktiviert.');
        }
        if ($participant->id === $owner->id) {
            throw new ValidationException('Du kannst das Notizbuch nicht mit dir selbst teilen.');
        }
        if ($this->shares->exists($participant->id, (int) $notebook['id'])) {
            throw new ValidationException('Dieser Nutzer hat bereits Zugriff auf das Notizbuch.');
        }

        $this->shares->add($participant->id, (int) $notebook['id']);

        return [
            'id' => $participant->id,
            'name' => $participant->name,
            'email' => $participant->email,
            'created_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function listParticipants(User $owner, int $notebookId): array
    {
        $notebook = $this->requireOwned($owner, $notebookId);

        return $this->shares->listParticipants((int) $notebook['id']);
    }

    public function removeParticipant(User $owner, int $notebookId, int $userId): void
    {
        $notebook = $this->requireOwned($owner, $notebookId);
        if ($userId === $owner->id) {
            throw new ValidationException('Der Eigentümer kann nicht als Teilnehmer entfernt werden.');
        }
        if (!$this->shares->exists($userId, (int) $notebook['id'])) {
            throw new NotFoundException('Teilnehmer nicht gefunden.');
        }

        $this->shares->remove($userId, (int) $notebook['id']);
    }

    public function leave(User $user, int $notebookId): void
    {
        $notebook = $this->shares->findSharedNotebookForUser($user->id, $notebookId);
        if ($notebook === null) {
            throw new NotFoundException('Dieses Notizbuch ist nicht mit dir geteilt.');
        }

        $this->shares->remove($user->id, (int) $notebook['id']);
    }

    /** @return array<string, mixed> */
    private function requireOwned(User $owner, int $notebookId): array
    {
        $workspaceId = $this->workspaces->findByUserId($owner->id);
        if ($workspaceId === null) {
            throw new \RuntimeException("Nutzer #{$owner->id} hat keinen Workspace.");
        }

        $notebook = $this->notebooks->findByIdForWorkspace($notebookId, $workspaceId);
        if ($notebook === null) {
            throw new NotFoundException("Notizbuch #{$notebookId} nicht gefunden.");
        }

        return $notebook;
    }
}
