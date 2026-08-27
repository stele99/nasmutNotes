<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\User;
use App\Domain\Voice\VoiceNoteService;
use App\Repositories\AuditLogRepository;
use App\Repositories\SettingsRepository;
use App\Support\ValidationException;

/**
 * Gemeinsame KI-Defaults: **ein** LLM-Modell für alle Bereiche und der
 * Reasoning-Aufwand als Vorgabe. Die Bereiche (Sprachnotizen, NotesVoice,
 * Notiz-KI, Desktop-Assistant) können den Reasoning-Aufwand je überschreiben;
 * das Modell gilt überall. Fehlt alles, fällt die Umgebungslösung
 * (VOICE_POSTPROCESS_MODEL) ein.
 *
 * Reasoning-Aufwand wird nur mitgeschickt, wenn er gesetzt ist - bei Modellen
 * ohne Reasoning-Unterstützung würde der Parameter den Aufruf sonst
 * scheitern lassen.
 */
final class AiModelSettings
{
    public const DEFAULT_MODEL_KEY = 'ai_default_model';
    public const DEFAULT_REASONING_KEY = 'ai_default_reasoning';

    /** Zulässige reasoning_effort-Werte der OpenAI-API (inkl. gpt-5-Serie). */
    public const REASONING_LEVELS = ['minimal', 'low', 'medium', 'high', 'xhigh', 'none'];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AuditLogRepository $auditLog,
        private readonly string $fallbackModel = '',
    ) {
    }

    /** Das eine LLM für alle Bereiche; leer heißt „nicht konfiguriert". */
    public function defaultModel(): string
    {
        $model = $this->settings->get(self::DEFAULT_MODEL_KEY);

        return $model === null || $model === '' ? $this->fallbackModel : trim($model);
    }

    /** Globaler Reasoning-Aufwand; leer heißt „nicht mitsenden". */
    public function defaultReasoning(): string
    {
        $value = $this->settings->get(self::DEFAULT_REASONING_KEY);

        return $value === null ? '' : $value;
    }

    /**
     * Reasoning-Aufwand eines Bereichs: eigene Einstellung schlägt den
     * globalen Default. Rückgabe leer = Parameter nicht mitsenden.
     */
    public function reasoningFor(string $areaKey): string
    {
        $value = $this->settings->get($areaKey);

        return $value !== null && $value !== '' ? $value : $this->defaultReasoning();
    }

    /** @return array{model: string, reasoning: string, base_url: string} */
    public function toAdminArray(): array
    {
        return [
            'model' => $this->defaultModel(),
            'reasoning' => $this->defaultReasoning(),
            'base_url' => (string) ($this->settings->get(VoiceNoteService::BASE_URL_KEY) ?? ''),
        ];
    }

    /**
     * Übernimmt Modell, Reasoning-Vorgabe und Dienst-Adresse aus dem
     * Admin-Dashboard.
     *
     * @param array<string, mixed> $input
     * @return array{model: string, reasoning: string, base_url: string}
     */
    public function setDefaults(User $admin, array $input, string $ipHash): array
    {
        $changed = [];

        if (array_key_exists('model', $input)) {
            $model = trim((string) (is_scalar($input['model']) ? $input['model'] : ''));
            if ($model !== '' && preg_match('/^[A-Za-z0-9._:\/-]{1,100}$/', $model) !== 1) {
                throw new ValidationException('Ungültiger Modellname.');
            }
            $this->settings->set(self::DEFAULT_MODEL_KEY, $model);
            $changed[] = 'model';
        }

        if (array_key_exists('reasoning', $input)) {
            $reasoning = trim((string) (is_scalar($input['reasoning']) ? $input['reasoning'] : ''));
            if ($reasoning !== '' && !in_array($reasoning, self::REASONING_LEVELS, true)) {
                throw new ValidationException(
                    'Der Reasoning-Aufwand muss einer der Stufen '
                    . implode(', ', self::REASONING_LEVELS) . ' sein oder leer bleiben.',
                );
            }
            $this->settings->set(self::DEFAULT_REASONING_KEY, $reasoning);
            $changed[] = 'reasoning';
        }

        if (array_key_exists('base_url', $input)) {
            // Die Dienst-Adresse wird zentral gepflegt (Sprachnotizen und
            // Assistant teilen sie); der Schlüssel bleibt aus Bestandsgründen
            // bei den Sprachnotizen.
            $url = trim((string) (is_scalar($input['base_url']) ? $input['base_url'] : ''));
            if ($url !== '') {
                if (filter_var($url, FILTER_VALIDATE_URL) === false || !str_starts_with($url, 'https://')) {
                    throw new ValidationException('Die Adresse des KI-Dienstes muss eine https-URL sein.');
                }
                $url = rtrim($url, '/');
            }
            $this->settings->set(VoiceNoteService::BASE_URL_KEY, $url);
            $changed[] = 'base_url';
        }

        $this->auditLog->log($admin->id, 'ai_defaults_changed', null, null, $ipHash, [
            'fields' => $changed,
        ]);

        return $this->toAdminArray();
    }

    /**
     * Validiert einen bereichsbezogenen Reasoning-Aufwand; leer erbt den
     * globalen Default.
     */
    public static function validatedReasoning(mixed $value): string
    {
        $reasoning = trim((string) (is_scalar($value) ? $value : ''));
        if ($reasoning !== '' && !in_array($reasoning, self::REASONING_LEVELS, true)) {
            throw new ValidationException(
                'Der Reasoning-Aufwand muss einer der Stufen '
                . implode(', ', self::REASONING_LEVELS) . ' sein oder leer bleiben.',
            );
        }

        return $reasoning;
    }
}
