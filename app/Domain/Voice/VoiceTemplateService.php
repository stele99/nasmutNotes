<?php

declare(strict_types=1);

namespace App\Domain\Voice;

use App\Domain\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\VoiceTemplateRepository;
use App\Support\NotFoundException;
use App\Support\ValidationException;

/**
 * Diktier-Vorlagen (Anweisung + Vokabular, die vor einer Aufnahme für eine
 * Notiz ausgewählt werden). Global (user_id NULL, vom Admin gepflegt) oder
 * persönlich (user_id = Eigentümer) - dieselbe Methode bedient beide Fälle:
 * $user === null heißt globaler Scope, ein User heißt Eigentümer-Scope.
 */
final class VoiceTemplateService
{
    private const MAX_NAME_LENGTH = 80;
    private const MAX_INSTRUCTION_LENGTH = 8000;
    /**
     * Das Vokabular geht als "prompt" an die Transkription, die davon nur die
     * letzten 224 Tokens berücksichtigt. Die Grenze bleibt bewusst darunter,
     * damit nichts stillschweigend beim Anbieter verfällt.
     */
    private const MAX_VOCABULARY_LENGTH = 600;

    /** Genug für unterschiedliche Anwendungsfälle, ohne dass die Auswahl unübersichtlich wird. */
    private const MAX_TEMPLATES_PER_SCOPE = 20;

    public function __construct(
        private readonly VoiceTemplateRepository $templates,
        private readonly AuditLogRepository $auditLog,
    ) {
    }

    /**
     * Für die Auswahl vor der Aufnahme: globale und eigene Vorlagen, global zuerst.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAvailableTo(User $user): array
    {
        return array_map([$this, 'serialize'], $this->templates->allAvailableTo($user->id));
    }

    /** @return array<int, array<string, mixed>> */
    public function listOwn(User $user): array
    {
        return array_map([$this, 'serialize'], $this->templates->allForUser($user->id));
    }

    /** @return array<int, array<string, mixed>> */
    public function listGlobal(): array
    {
        return array_map([$this, 'serialize'], $this->templates->allGlobal());
    }

    /**
     * $ownerId wählt den Bereich (null = globale Admin-Vorlage), $actor ist
     * stets der handelnde Mensch - beides auseinanderzuhalten hält das
     * Audit-Log aussagekräftig, auch wenn ein Admin global arbeitet.
     *
     * @return array<string, mixed>
     */
    public function create(
        User $actor,
        ?int $ownerId,
        string $name,
        string $instruction,
        string $vocabulary,
        string $ipHash,
    ): array {
        [$name, $instruction, $vocabulary] = $this->validate($name, $instruction, $vocabulary);

        if ($this->templates->countForScope($ownerId) >= self::MAX_TEMPLATES_PER_SCOPE) {
            throw new ValidationException(
                'Es sind bereits ' . self::MAX_TEMPLATES_PER_SCOPE . ' Vorlagen angelegt.'
                . ' Bitte zuerst eine nicht mehr benötigte löschen.',
            );
        }

        $id = $this->templates->create($ownerId, $name, $instruction, $vocabulary);

        $this->auditLog->log($actor->id, 'voice_template_created', 'voice_template', $id, $ipHash, [
            'name' => $name,
            'global' => $ownerId === null,
        ]);

        return $this->serialize($this->mustFind($id));
    }

    /** @return array<string, mixed> */
    public function update(
        User $actor,
        ?int $ownerId,
        int $id,
        string $name,
        string $instruction,
        string $vocabulary,
        string $ipHash,
    ): array {
        $this->findInScope($ownerId, $id);
        [$name, $instruction, $vocabulary] = $this->validate($name, $instruction, $vocabulary);

        $this->templates->update($id, $name, $instruction, $vocabulary);

        $this->auditLog->log($actor->id, 'voice_template_updated', 'voice_template', $id, $ipHash, [
            'name' => $name,
        ]);

        return $this->serialize($this->mustFind($id));
    }

    public function delete(User $actor, ?int $ownerId, int $id, string $ipHash): void
    {
        $this->findInScope($ownerId, $id);

        // Ohne wenigstens eine globale Vorlage könnte niemand mehr diktieren,
        // der sich keine eigene angelegt hat - das Diktat wäre instanzweit tot.
        if ($ownerId === null && $this->templates->countForScope(null) <= 1) {
            throw new ValidationException(
                'Die letzte globale Vorlage kann nicht gelöscht werden.'
                . ' Ohne sie könnte niemand mehr eine Notiz diktieren.',
            );
        }

        $this->templates->delete($id);

        $this->auditLog->log($actor->id, 'voice_template_deleted', 'voice_template', $id, $ipHash);
    }

    /**
     * Löst eine Vorlage auf, die für die Aufbereitung eines Diktats verwendet
     * werden darf: entweder global oder dem Nutzer gehörend. Einziger Ort
     * dieser Prüfung - der Diktatweg geht ebenfalls hierüber.
     *
     * @return array<string, mixed>
     */
    public function resolveAccessible(User $user, int $id): array
    {
        $row = $this->templates->findById($id);
        $ownerId = $row !== null && $row['user_id'] !== null ? (int) $row['user_id'] : null;
        if ($row === null || !($ownerId === null || $ownerId === $user->id)) {
            throw new ValidationException('Bitte eine Vorlage auswählen.');
        }

        return $row;
    }

    /**
     * Vorlage genau eines Bereichs: mit $ownerId ausschließlich die eigenen,
     * ohne ihn ausschließlich die globalen. Bewusst exklusiv - wer eine fremde
     * oder bereichsfremde Vorlage anfasst, bekommt "nicht gefunden".
     *
     * @return array<string, mixed>
     */
    private function findInScope(?int $ownerId, int $id): array
    {
        $row = $this->templates->findById($id);
        $rowOwnerId = $row !== null && $row['user_id'] !== null ? (int) $row['user_id'] : null;
        if ($row === null || $rowOwnerId !== $ownerId) {
            throw new NotFoundException('Vorlage nicht gefunden.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function mustFind(int $id): array
    {
        $row = $this->templates->findById($id);
        if ($row === null) {
            throw new NotFoundException('Vorlage nicht gefunden.');
        }

        return $row;
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function validate(string $name, string $instruction, string $vocabulary): array
    {
        $name = trim($name);
        $instruction = trim($instruction);
        $vocabulary = trim($vocabulary);

        if ($name === '') {
            throw new ValidationException('Bitte einen Namen für die Vorlage angeben.');
        }
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new ValidationException('Der Name darf höchstens ' . self::MAX_NAME_LENGTH . ' Zeichen lang sein.');
        }
        if ($instruction === '') {
            throw new ValidationException('Bitte eine Anweisung für die Vorlage angeben.');
        }
        if (mb_strlen($instruction) > self::MAX_INSTRUCTION_LENGTH) {
            throw new ValidationException(
                'Die Anweisung darf höchstens ' . self::MAX_INSTRUCTION_LENGTH . ' Zeichen lang sein.',
            );
        }
        if (mb_strlen($vocabulary) > self::MAX_VOCABULARY_LENGTH) {
            throw new ValidationException(
                'Das Vokabular darf höchstens ' . self::MAX_VOCABULARY_LENGTH . ' Zeichen lang sein.',
            );
        }

        return [$name, $instruction, $vocabulary];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serialize(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'instruction' => (string) $row['instruction'],
            'vocabulary' => (string) $row['vocabulary'],
            'scope' => $row['user_id'] === null ? 'global' : 'own',
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
