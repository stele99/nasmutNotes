<?php

declare(strict_types=1);

namespace App\Domain\Voice;

use App\Domain\Ai\AiCallContext;
use App\Domain\Import\MarkdownConverter;
use App\Domain\Log\LogColumnType;
use App\Domain\NotebookService;
use App\Domain\Notes\NoteService;
use App\Domain\PageService;
use App\Domain\User;
use App\Repositories\AuditLogRepository;
use App\Repositories\SettingsRepository;
use App\Support\ValidationException;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Sprachnotizen: Aufnahme entgegennehmen, per OpenAI transkribieren, optional
 * durch ein zweites Modell aufbereiten und daraus Überschrift und passendes
 * Notizbuch ableiten (FR-VOICE-01..06).
 *
 * Sämtliche Parameter liegen in app_settings und sind im Admin-Dashboard
 * änderbar; die .env liefert nur die Anfangswerte.
 */
final class VoiceNoteService
{
    public const ENABLED_KEY = 'voice_enabled';
    public const BASE_URL_KEY = 'voice_openai_base_url';
    public const TRANSCRIBE_MODEL_KEY = 'voice_transcribe_model';
    public const LANGUAGE_KEY = 'voice_language';
    public const POSTPROCESS_ENABLED_KEY = 'voice_postprocess_enabled';
    public const POSTPROCESS_MODEL_KEY = 'voice_postprocess_model';
    public const POSTPROCESS_PROMPT_KEY = 'voice_postprocess_prompt';
    public const LOG_PROMPT_KEY = 'voice_log_prompt';
    public const MAX_SECONDS_KEY = 'voice_max_seconds';
    public const MAX_MB_KEY = 'voice_max_mb';

    /**
     * NotesVoice (FR-NVOICE): Schnellerfassung direkt in die Zwischenablage,
     * ausgelöst über einen Automations-Token statt der offenen Notiz. Eigenes
     * Modell und eigene Anweisung, weil hier - anders als bei den übrigen
     * Diktatwegen - weder Titel noch Notizbuch noch Markdown gebraucht werden.
     */
    public const QUICK_MODEL_KEY = 'voice_quick_model';
    public const QUICK_PROMPT_KEY = 'voice_quick_prompt';

    public const DEFAULT_BASE_URL = 'https://api.openai.com/v1';
    public const DEFAULT_TRANSCRIBE_MODEL = 'gpt-4o-mini-transcribe';
    public const DEFAULT_POSTPROCESS_MODEL = 'gpt-4o-mini';
    public const DEFAULT_MAX_SECONDS = 300;

    /** Obergrenze der OpenAI-Transkription; größere Dateien lehnt sie ab. */
    public const MAX_UPLOAD_MB = 25;

    public const DEFAULT_PROMPT = <<<'PROMPT'
        Du bereitest diktierte Sprachnotizen auf. Du bekommst das Roh-Transkript einer Aufnahme.

        Aufgaben:
        1. Bereinige Erkennungsfehler, Versprecher und Füllwörter, setze Satzzeichen und Absätze. Der Inhalt bleibt unverändert - nichts ergänzen, nichts weglassen, nicht zusammenfassen.
        2. Gliedere den Text in Markdown: Absätze durch Leerzeilen, Aufzählungen als "- ", Aufgaben als "- [ ] ", Zwischenüberschriften höchstens als "## ".
        3. Formuliere eine kurze Überschrift von höchstens 60 Zeichen ohne Satzzeichen am Ende.
        4. Wähle aus der Liste vorhandener Notizbücher das inhaltlich passende aus. Passt keines eindeutig, gib einen leeren String zurück. Erfinde keine neuen Namen.

        Antworte ausschließlich mit einem JSON-Objekt der Form:
        {"title": "…", "notebook": "…", "text": "…"}

        Schreibe in der Sprache des Transkripts.
        PROMPT;

    public const DEFAULT_LOG_PROMPT = <<<'PROMPT'
        Du erfasst aus einer diktierten Aufnahme genau einen Eintrag für ein Logbuch.

        Du bekommst die Spalten des Logbuchs mit ihrer Art, die aktuelle Ortszeit und das Transkript.

        Bedeutung der Spaltenarten:
        - text: Freitext
        - standort: Ortsangabe als Text (Adresse, Ortsname)
        - uhrzeit: Uhrzeit im Format HH:MM
        - stunden: Dezimalzahl (eine Dreiviertelstunde ist 0.75)
        - zahl: Dezimalzahl
        - betrag: Geldbetrag in Euro als Dezimalzahl, ohne Währungszeichen

        Antworte ausschließlich mit einem JSON-Objekt der Form:
        {"occurred_at": "YYYY-MM-DDTHH:MM", "values": {"<Spaltenname>": "<Wert>"}}

        Regeln:
        1. "occurred_at" ist der Zeitpunkt des Eintrags in Ortszeit. Relative Angaben wie „gestern früh" oder „vor zwei Stunden" von der genannten aktuellen Zeit aus umrechnen. Nennt die Aufnahme keinen Zeitpunkt, nimm die aktuelle Zeit.
        2. Führe nur Spalten auf, zu denen die Aufnahme tatsächlich etwas sagt. Schreibe die Spaltennamen genau so, wie sie vorgegeben sind.
        3. Zahlen ohne Einheit und mit Punkt als Dezimaltrennzeichen.
        4. Erfinde nichts. Was nicht gesagt wurde, bleibt weg.
        PROMPT;

    public const DEFAULT_TASKS_PROMPT = <<<'PROMPT'
        Du zerlegst eine diktierte Aufnahme in einzelne Aufgaben für eine Aufgabenliste.

        Aufgaben:
        1. Erkenne, ob eine oder mehrere Aufgaben genannt wurden - etwa durch Aufzählung ("und", "außerdem", "dann noch") oder getrennte Sätze - und liste jede als eigenen kurzen Titel auf.
        2. Entferne Füllwörter und Versprecher, der Inhalt jeder Aufgabe bleibt aber vollständig erhalten. Kein Satzzeichen am Ende des Titels.
        3. Erfinde nichts, was nicht gesagt wurde.

        Antworte ausschließlich mit einem JSON-Objekt der Form:
        {"tasks": ["…", "…"]}

        Schreibe in der Sprache des Transkripts.
        PROMPT;

    public const DEFAULT_QUICK_PROMPT = <<<'PROMPT'
        Du bereitest ein diktiertes Sprachtranskript für die Zwischenablage auf.

        Aufgaben:
        1. Bereinige Erkennungsfehler, Versprecher und Füllwörter, setze Satzzeichen und Absätze. Der Inhalt bleibt unverändert - nichts ergänzen, nichts weglassen, nicht zusammenfassen.
        2. Schreibe als normalen Fließtext ohne Markdown-Syntax (keine "-", "#", "**"), damit sich der Text überall einfügen lässt.
        3. Erfinde nichts.

        Antworte ausschließlich mit einem JSON-Objekt der Form:
        {"text": "…"}

        Schreibe in der Sprache des Transkripts.
        PROMPT;

    /** Zulässige Aufnahmeformate: Endung => MIME-Typen, die Browser melden. */
    private const AUDIO_FORMATS = [
        'webm' => ['audio/webm', 'video/webm'],
        'mp4' => ['audio/mp4', 'video/mp4'],
        'm4a' => ['audio/m4a', 'audio/x-m4a'],
        'mp3' => ['audio/mpeg', 'audio/mp3'],
        'ogg' => ['audio/ogg', 'application/ogg'],
        'wav' => ['audio/wav', 'audio/x-wav', 'audio/wave'],
        'flac' => ['audio/flac', 'audio/x-flac'],
    ];

    /**
     * Der API-Schlüssel kommt ausschließlich aus der Umgebung (OPENAI_KEY) und
     * liegt bewusst nicht in der Datenbank: Ein Geheimnis gehört zum Deployment,
     * nicht in eine Sicherungsdatei des Workspaces.
     */
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly OpenAiClient $client,
        private readonly PageService $pages,
        private readonly NoteService $notes,
        private readonly NotebookService $notebooks,
        private readonly MarkdownConverter $markdown,
        private readonly AuditLogRepository $auditLog,
        private readonly string $tmpPath,
        private readonly string $apiKey = '',
        private readonly string $fallbackBaseUrl = self::DEFAULT_BASE_URL,
        private readonly string $fallbackTranscribeModel = self::DEFAULT_TRANSCRIBE_MODEL,
        private readonly string $fallbackPostprocessModel = self::DEFAULT_POSTPROCESS_MODEL,
        private readonly string $fallbackLanguage = 'de',
        private readonly int $fallbackMaxSeconds = self::DEFAULT_MAX_SECONDS,
        private readonly int $fallbackMaxMb = self::MAX_UPLOAD_MB,
    ) {
    }

    public function settings(): VoiceSettings
    {
        return new VoiceSettings(
            // Ohne ausdrückliche Entscheidung im Dashboard richtet sich die
            // Freischaltung danach, ob die Umgebung einen Schlüssel mitbringt.
            enabled: $this->boolSetting(self::ENABLED_KEY, $this->apiKey !== ''),
            apiKey: trim($this->apiKey),
            baseUrl: $this->stringSetting(self::BASE_URL_KEY, $this->fallbackBaseUrl),
            transcribeModel: $this->stringSetting(self::TRANSCRIBE_MODEL_KEY, $this->fallbackTranscribeModel),
            language: $this->stringSetting(self::LANGUAGE_KEY, $this->fallbackLanguage),
            postprocessEnabled: $this->boolSetting(self::POSTPROCESS_ENABLED_KEY, true),
            postprocessModel: $this->stringSetting(self::POSTPROCESS_MODEL_KEY, $this->fallbackPostprocessModel),
            postprocessPrompt: $this->stringSetting(self::POSTPROCESS_PROMPT_KEY, self::DEFAULT_PROMPT),
            logPrompt: $this->stringSetting(self::LOG_PROMPT_KEY, self::DEFAULT_LOG_PROMPT),
            maxSeconds: max(10, $this->settings->getInt(self::MAX_SECONDS_KEY, $this->fallbackMaxSeconds)
                ?? $this->fallbackMaxSeconds),
            maxMb: min(self::MAX_UPLOAD_MB, max(1, $this->settings->getInt(self::MAX_MB_KEY, $this->fallbackMaxMb)
                ?? $this->fallbackMaxMb)),
            quickModel: $this->stringSetting(self::QUICK_MODEL_KEY, $this->fallbackPostprocessModel),
            quickPrompt: $this->stringSetting(self::QUICK_PROMPT_KEY, self::DEFAULT_QUICK_PROMPT),
        );
    }

    /** Was der Client wissen muss, um den Aufnahmeknopf anzubieten. */
    public function isUsable(): bool
    {
        return $this->settings()->isUsable();
    }

    /**
     * Übernimmt die im Admin-Dashboard geänderten Werte. Nur übermittelte
     * Schlüssel werden angefasst, damit ein Teilformular nichts überschreibt.
     *
     * @param array<string, mixed> $input
     */
    public function updateSettings(User $admin, array $input, string $ipHash): VoiceSettings
    {
        $changed = [];

        if (array_key_exists('enabled', $input)) {
            $this->settings->set(self::ENABLED_KEY, $this->toBool($input['enabled']) ? '1' : '0');
            $changed[] = 'enabled';
        }

        if (array_key_exists('base_url', $input)) {
            $this->settings->set(self::BASE_URL_KEY, $this->validatedBaseUrl($input['base_url']));
            $changed[] = 'base_url';
        }

        if (array_key_exists('transcribe_model', $input)) {
            $this->settings->set(self::TRANSCRIBE_MODEL_KEY, $this->validatedModel($input['transcribe_model']));
            $changed[] = 'transcribe_model';
        }

        if (array_key_exists('language', $input)) {
            $this->settings->set(self::LANGUAGE_KEY, $this->validatedLanguage($input['language']));
            $changed[] = 'language';
        }

        if (array_key_exists('postprocess_enabled', $input)) {
            $this->settings->set(
                self::POSTPROCESS_ENABLED_KEY,
                $this->toBool($input['postprocess_enabled']) ? '1' : '0',
            );
            $changed[] = 'postprocess_enabled';
        }

        if (array_key_exists('postprocess_model', $input)) {
            $this->settings->set(self::POSTPROCESS_MODEL_KEY, $this->validatedModel($input['postprocess_model']));
            $changed[] = 'postprocess_model';
        }

        if (array_key_exists('postprocess_prompt', $input)) {
            $prompt = trim((string) ($input['postprocess_prompt'] ?? ''));
            if ($prompt === '') {
                $prompt = self::DEFAULT_PROMPT;
            }
            if (mb_strlen($prompt) > 8000) {
                throw new ValidationException('Die Anweisung darf höchstens 8000 Zeichen lang sein.');
            }
            $this->settings->set(self::POSTPROCESS_PROMPT_KEY, $prompt);
            $changed[] = 'postprocess_prompt';
        }

        if (array_key_exists('log_prompt', $input)) {
            $prompt = trim((string) ($input['log_prompt'] ?? ''));
            if ($prompt === '') {
                $prompt = self::DEFAULT_LOG_PROMPT;
            }
            if (mb_strlen($prompt) > 8000) {
                throw new ValidationException('Die Anweisung darf höchstens 8000 Zeichen lang sein.');
            }
            $this->settings->set(self::LOG_PROMPT_KEY, $prompt);
            $changed[] = 'log_prompt';
        }

        if (array_key_exists('quick_model', $input)) {
            $this->settings->set(self::QUICK_MODEL_KEY, $this->validatedModel($input['quick_model']));
            $changed[] = 'quick_model';
        }

        if (array_key_exists('quick_prompt', $input)) {
            $prompt = trim((string) ($input['quick_prompt'] ?? ''));
            if ($prompt === '') {
                $prompt = self::DEFAULT_QUICK_PROMPT;
            }
            if (mb_strlen($prompt) > 8000) {
                throw new ValidationException('Die Anweisung darf höchstens 8000 Zeichen lang sein.');
            }
            $this->settings->set(self::QUICK_PROMPT_KEY, $prompt);
            $changed[] = 'quick_prompt';
        }

        if (array_key_exists('max_seconds', $input)) {
            $seconds = (int) $input['max_seconds'];
            if ($seconds < 10 || $seconds > 3600) {
                throw new ValidationException('Die Aufnahmedauer muss zwischen 10 und 3600 Sekunden liegen.');
            }
            $this->settings->set(self::MAX_SECONDS_KEY, (string) $seconds);
            $changed[] = 'max_seconds';
        }

        if (array_key_exists('max_mb', $input)) {
            $maxMb = (int) $input['max_mb'];
            if ($maxMb < 1 || $maxMb > self::MAX_UPLOAD_MB) {
                throw new ValidationException(
                    'Die Aufnahmegröße muss zwischen 1 und ' . self::MAX_UPLOAD_MB . ' MB liegen.',
                );
            }
            $this->settings->set(self::MAX_MB_KEY, (string) $maxMb);
            $changed[] = 'max_mb';
        }

        $this->auditLog->log($admin->id, 'voice_settings_changed', null, null, $ipHash, ['fields' => $changed]);

        return $this->settings();
    }

    /**
     * Transkribiert eine Aufnahme und liefert den aufbereiteten Text samt
     * Überschriftsvorschlag und abgeleitetem Notizbuch - ohne etwas zu
     * speichern. Genutzt beim Diktat in eine offene Notiz.
     *
     * @return array{
     *     transcript: string,
     *     markdown: string,
     *     title: string,
     *     notebook_id: ?int,
     *     notebook_name: ?string,
     *     document: array<string, mixed>
     * }
     */
    public function transcribe(User $user, UploadedFileInterface $file): array
    {
        $settings = $this->requireUsableSettings();
        $context = new AiCallContext($user->id, 'voice_note');
        $filename = $this->storeUpload($file, $settings);

        try {
            $transcript = $this->client->transcribe($settings, $filename['path'], $filename['name'], $context);
        } finally {
            @unlink($filename['path']);
        }

        if ($transcript === '') {
            throw new ValidationException('In der Aufnahme wurde keine Sprache erkannt.');
        }

        $refined = $this->refine($user, $settings, $transcript, $context);
        $document = $this->markdown->toDocument($refined['markdown']);
        if (($document['content'] ?? []) === []) {
            $document = $this->markdown->toDocument($transcript);
        }

        return [
            'transcript' => $transcript,
            'markdown' => $refined['markdown'],
            'title' => $refined['title'],
            'notebook_id' => $refined['notebook_id'],
            'notebook_name' => $refined['notebook_name'],
            'document' => $document,
        ];
    }

    /**
     * Diktat in eine bestehende Notiz. Der Zielzustand wird geprüft, bevor
     * Audiodaten gespeichert oder an den Anbieter gesendet werden.
     *
     * @return array{
     *     transcript: string,
     *     markdown: string,
     *     title: string,
     *     notebook_id: ?int,
     *     notebook_name: ?string,
     *     document: array<string, mixed>
     * }
     */
    public function transcribeForPage(User $user, int $pageId, UploadedFileInterface $file): array
    {
        $page = $this->pages->find($user, $pageId);
        if (($page['type'] ?? null) !== 'note') {
            throw new ValidationException('Das Diktatziel ist keine Notizseite.');
        }
        $this->pages->assertNotEncrypted($page);
        $this->pages->assertCanWrite($user, $pageId);

        return $this->transcribe($user, $file);
    }

    /**
     * NotesVoice (FR-NVOICE): Schnellerfassung für die Zwischenablage. Anders
     * als transcribe() ohne Titel, Notizbuchzuordnung oder ProseMirror-
     * Dokument - es wird nichts gespeichert, der Text verlässt den Server nur
     * in der Antwort.
     *
     * @return array{text: string, transcript: string}
     */
    public function transcribeQuick(User $user, UploadedFileInterface $file): array
    {
        $settings = $this->requireUsableSettings();
        $context = new AiCallContext($user->id, 'voice_quick');
        $upload = $this->storeUpload($file, $settings);

        try {
            $transcript = $this->client->transcribe($settings, $upload['path'], $upload['name'], $context);
        } finally {
            @unlink($upload['path']);
        }

        if ($transcript === '') {
            throw new ValidationException('In der Aufnahme wurde keine Sprache erkannt.');
        }

        return ['text' => $this->refineQuick($settings, $transcript, $context), 'transcript' => $transcript];
    }

    /**
     * Legt aus einer Aufnahme eine fertige Notiz an: Überschrift und Notizbuch
     * stammen aus der Nachbearbeitung, der Text steht bereits im Inhalt.
     *
     * @param array<string, mixed>|null $location Aufnahmeort, falls der Client ihn mitschickt.
     * @return array{page: array<string, mixed>, transcript: string, title: string, notebook_name: ?string}
     */
    public function createNote(
        User $user,
        UploadedFileInterface $file,
        string $ipHash,
        ?array $location = null,
    ): array {
        $result = $this->transcribe($user, $file);

        $page = $this->pages->create($user, 'note', $result['title'], null, $result['notebook_id'], $location);
        $pageId = (int) $page['id'];

        try {
            $this->notes->save($user, $pageId, $result['document'], 1);
        } catch (\Throwable $e) {
            // Lässt sich der diktierte Text nicht speichern, bleibt sonst eine
            // leere Seite im Workspace zurück.
            $this->pages->purge($user, $pageId);

            throw $e;
        }

        $this->auditLog->log($user->id, 'voice_note_created', 'page', $pageId, $ipHash, [
            'characters' => mb_strlen($result['transcript']),
            'notebook_id' => $result['notebook_id'],
        ]);

        return [
            'page' => $this->pages->find($user, $pageId),
            'transcript' => $result['transcript'],
            'title' => $result['title'],
            'notebook_name' => $result['notebook_name'],
        ];
    }

    /**
     * Diktierter Logbuch-Eintrag (FR-LOG-08): Die Aufnahme wird transkribiert
     * und auf die Spalten des Logbuchs verteilt. Gespeichert wird hier nichts -
     * das übernimmt der LogService mit seiner eigenen Prüfung.
     *
     * **Ortsspalten bleiben ausgespart**: Sie kommen bei einer Aufnahme immer
     * vom Ortungsdienst des Geräts (FR-LOG-11). Ein Modell könnte aus dem
     * Gesagten nur raten, wo der Eintrag entstanden ist.
     *
     * @param array<int, array<string, mixed>> $columns Spalten des Logbuchs
     * @param string|null $clientNow Ortszeit des Clients als ISO-Zeitstempel mit Zeitzone
     * @return array{occurred_at: ?string, values: array<int, string>, transcript: string}
     */
    public function transcribeForLog(User $user, UploadedFileInterface $file, array $columns, ?string $clientNow = null): array
    {
        $columns = array_values(array_filter(
            $columns,
            static fn (array $column): bool => !in_array(
                (string) $column['type'],
                [LogColumnType::Location->value, LogColumnType::User->value],
                true,
            ),
        ));

        $settings = $this->requireUsableSettings();
        $context = new AiCallContext($user->id, 'voice_log');
        $upload = $this->storeUpload($file, $settings);

        try {
            $transcript = $this->client->transcribe($settings, $upload['path'], $upload['name'], $context);
        } finally {
            @unlink($upload['path']);
        }

        if ($transcript === '') {
            throw new ValidationException('In der Aufnahme wurde keine Sprache erkannt.');
        }

        $reference = $this->referenceTime($clientNow);
        $lines = array_map(
            static fn (array $column): string => '- ' . $column['name'] . ' | '
                . LogColumnType::from((string) $column['type'])->promptName(),
            $columns,
        );

        $answer = $this->client->completeJson(
            $settings,
            $settings->logPrompt,
            "Spalten des Logbuchs:\n" . implode("\n", $lines)
            . "\n\nAktuelle Ortszeit: " . $reference->format('Y-m-d\TH:i')
            . "\n\nTranskript der Aufnahme:\n" . $transcript,
            null,
            $context,
        );

        return [
            'occurred_at' => $this->localTimestamp($answer['occurred_at'] ?? null, $reference),
            'values' => $this->mapValuesToColumns($answer['values'] ?? null, $columns),
            'transcript' => $transcript,
        ];
    }

    /**
     * Diktierte Aufgabe(n) für eine Aufgabenliste: Die Aufnahme wird
     * transkribiert und in einen oder mehrere Aufgabentitel zerlegt.
     * Gespeichert wird hier nichts - das übernimmt der TaskBoardService mit
     * seiner eigenen Titelprüfung (Länge, Duplikate).
     *
     * @return array{titles: array<int, string>, transcript: string}
     */
    public function transcribeForTasks(User $user, UploadedFileInterface $file): array
    {
        $settings = $this->requireUsableSettings();
        $context = new AiCallContext($user->id, 'voice_tasks');
        $upload = $this->storeUpload($file, $settings);

        try {
            $transcript = $this->client->transcribe($settings, $upload['path'], $upload['name'], $context);
        } finally {
            @unlink($upload['path']);
        }

        if ($transcript === '') {
            throw new ValidationException('In der Aufnahme wurde keine Sprache erkannt.');
        }

        $answer = $this->client->completeJson(
            $settings,
            self::DEFAULT_TASKS_PROMPT,
            "Transkript der Aufnahme:\n" . $transcript,
            null,
            $context,
        );

        $titles = [];
        foreach ((array) ($answer['tasks'] ?? []) as $title) {
            if (!is_scalar($title)) {
                continue;
            }
            $title = trim((string) $title);
            if ($title !== '') {
                $titles[] = $title;
            }
        }

        // Ohne verwertbare Zerlegung ist das ganze Transkript immer noch eine
        // Aufgabe - besser das als gar keine.
        if ($titles === []) {
            $titles = [$transcript];
        }

        return ['titles' => $titles, 'transcript' => $transcript];
    }

    /**
     * Das Modell antwortet mit Spaltennamen; die Zuordnung erfolgt ohne
     * Rücksicht auf Groß-/Kleinschreibung. Unbekannte Namen fallen weg, statt
     * den ganzen Eintrag scheitern zu lassen.
     *
     * @param array<int, array<string, mixed>> $columns
     * @return array<int, string>
     */
    private function mapValuesToColumns(mixed $values, array $columns): array
    {
        if (!is_array($values)) {
            return [];
        }

        $byName = [];
        foreach ($columns as $column) {
            $byName[mb_strtolower(trim((string) $column['name']))] = (int) $column['id'];
        }

        $mapped = [];
        foreach ($values as $name => $value) {
            $columnId = $byName[mb_strtolower(trim((string) $name))] ?? null;
            if ($columnId === null || !is_scalar($value)) {
                continue;
            }
            $text = trim((string) $value);
            if ($text !== '') {
                $mapped[$columnId] = $text;
            }
        }

        return $mapped;
    }

    /** Ortszeit des Clients; ohne brauchbare Angabe gilt die Serverzeit. */
    private function referenceTime(?string $clientNow): \DateTimeImmutable
    {
        if ($clientNow !== null && trim($clientNow) !== '') {
            try {
                return new \DateTimeImmutable(trim($clientNow));
            } catch (\Throwable) {
                /* Serverzeit ist der Rückfall. */
            }
        }

        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Das Modell liefert eine Ortszeit ohne Zeitzone. Ergänzt wird die des
     * Clients, damit „gestern um acht" nicht um Stunden verrutscht.
     */
    private function localTimestamp(mixed $value, \DateTimeImmutable $reference): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value) === 1) {
            return $value;
        }

        try {
            return new \DateTimeImmutable($value, $reference->getTimezone())->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Zweiter Modellaufruf: Rohtext putzen, Überschrift bilden, Notizbuch
     * zuordnen. Ist die Nachbearbeitung aus, bleibt es beim Rohtext mit einer
     * aus dem Anfang gebildeten Überschrift.
     *
     * @return array{markdown: string, title: string, notebook_id: ?int, notebook_name: ?string}
     */
    private function refine(User $user, VoiceSettings $settings, string $transcript, AiCallContext $context): array
    {
        $fallback = [
            'markdown' => $transcript,
            'title' => $this->titleFromText($transcript),
            'notebook_id' => null,
            'notebook_name' => null,
        ];

        if (!$settings->postprocessEnabled) {
            return $fallback;
        }

        $notebooks = $this->notebooks->list($user);
        $names = array_values(array_map(
            static fn (array $notebook): string => (string) $notebook['name'],
            $notebooks,
        ));

        $userPrompt = "Vorhandene Notizbücher:\n"
            . ($names === [] ? '(keine)' : '- ' . implode("\n- ", $names))
            . "\n\nRoh-Transkript:\n" . $transcript;

        $answer = $this->client->completeJson($settings, $settings->postprocessPrompt, $userPrompt, null, $context);

        $text = trim((string) (is_scalar($answer['text'] ?? null) ? $answer['text'] : ''));
        $title = trim((string) (is_scalar($answer['title'] ?? null) ? $answer['title'] : ''));
        $notebookName = trim((string) (is_scalar($answer['notebook'] ?? null) ? $answer['notebook'] : ''));

        $matched = $this->matchNotebook($notebooks, $notebookName);

        return [
            'markdown' => $text !== '' ? $text : $transcript,
            'title' => $title !== '' ? $this->trimTitle($title) : $fallback['title'],
            'notebook_id' => $matched === null ? null : (int) $matched['id'],
            'notebook_name' => $matched === null ? null : (string) $matched['name'],
        ];
    }

    /**
     * Nachbearbeitung für NotesVoice: eigenes Modell, eigener Prompt, kein
     * Notizbuch-Matching. Ist die Nachbearbeitung insgesamt abgeschaltet oder
     * liefert das Modell nichts Verwertbares, bleibt es beim getrimmten
     * Rohtranskript.
     */
    private function refineQuick(VoiceSettings $settings, string $transcript, AiCallContext $context): string
    {
        if (!$settings->postprocessEnabled) {
            return $transcript;
        }

        $answer = $this->client->completeJson(
            $settings,
            $settings->quickPrompt,
            "Transkript der Aufnahme:\n" . $transcript,
            $settings->quickModel,
            $context,
        );

        $text = trim((string) (is_scalar($answer['text'] ?? null) ? $answer['text'] : ''));

        return $text !== '' ? $text : $transcript;
    }

    /**
     * Zugeordnet wird nur auf ein bereits vorhandenes Notizbuch - ein vom
     * Modell erfundener Name darf keinen neuen Ordner anlegen.
     *
     * @param array<int, array<string, mixed>> $notebooks
     * @return array<string, mixed>|null
     */
    private function matchNotebook(array $notebooks, string $name): ?array
    {
        $needle = mb_strtolower(trim($name));
        if ($needle === '') {
            return null;
        }

        foreach ($notebooks as $notebook) {
            if (mb_strtolower((string) $notebook['name']) === $needle) {
                return $notebook;
            }
        }

        return null;
    }

    private function titleFromText(string $text): string
    {
        $firstSentence = preg_split('/(?<=[.!?])\s+|\R/u', trim($text), 2);
        $candidate = is_array($firstSentence) && $firstSentence !== [] ? trim($firstSentence[0]) : '';

        return $this->trimTitle($candidate !== '' ? $candidate : 'Sprachnotiz');
    }

    private function trimTitle(string $title): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        $title = rtrim($title, " \t.,;:-");
        if ($title === '') {
            return 'Sprachnotiz';
        }

        return mb_strlen($title) > 80 ? mb_substr($title, 0, 77) . '…' : $title;
    }

    /**
     * Schreibt die Aufnahme mit passender Endung in ein temporäres Verzeichnis:
     * Die Transkription erkennt das Format über den Dateinamen.
     *
     * @return array{path: string, name: string}
     */
    private function storeUpload(UploadedFileInterface $file, VoiceSettings $settings): array
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException('Die Aufnahme wurde nicht vollständig übertragen.');
        }

        $size = $file->getSize() ?? 0;
        if ($size <= 0) {
            throw new ValidationException('Die Aufnahme ist leer.');
        }
        if ($size > $settings->maxBytes()) {
            throw new ValidationException("Die Aufnahme ist größer als {$settings->maxMb} MB.");
        }

        $extension = $this->extensionFor($file);

        if (!is_dir($this->tmpPath) && !mkdir($this->tmpPath, 0o770, true) && !is_dir($this->tmpPath)) {
            throw new \RuntimeException("Ablage für Aufnahmen nicht anlegbar: {$this->tmpPath}");
        }

        $name = 'voice-' . bin2hex(random_bytes(8)) . '.' . $extension;
        $path = $this->tmpPath . '/' . $name;
        $file->moveTo($path);

        return ['path' => $path, 'name' => $name];
    }

    private function extensionFor(UploadedFileInterface $file): string
    {
        // Der Browser meldet den Container mitsamt Codec ("audio/webm;codecs=opus").
        $mime = strtolower(trim(explode(';', (string) $file->getClientMediaType())[0]));

        foreach (self::AUDIO_FORMATS as $extension => $mimeTypes) {
            if (in_array($mime, $mimeTypes, true)) {
                return $extension;
            }
        }

        $clientExtension = strtolower(pathinfo((string) $file->getClientFilename(), PATHINFO_EXTENSION));
        if (array_key_exists($clientExtension, self::AUDIO_FORMATS)) {
            return $clientExtension;
        }

        throw new ValidationException('Dieses Aufnahmeformat wird nicht unterstützt.');
    }

    private function requireUsableSettings(): VoiceSettings
    {
        $settings = $this->settings();
        if (!$settings->enabled) {
            throw new ValidationException('Sprachnotizen sind nicht freigeschaltet.');
        }
        if ($settings->apiKey === '') {
            throw new ValidationException('In der Serverkonfiguration fehlt OPENAI_KEY.');
        }

        return $settings;
    }

    private function stringSetting(string $key, string $default): string
    {
        $value = $this->settings->get($key);

        return $value === null ? $default : $value;
    }

    private function boolSetting(string $key, bool $default): bool
    {
        $value = $this->settings->get($key);

        return $value === null || $value === '' ? $default : $value === '1';
    }

    private function toBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function validatedModel(mixed $value): string
    {
        $model = trim((string) (is_scalar($value) ? $value : ''));
        if ($model === '' || mb_strlen($model) > 100 || preg_match('/^[A-Za-z0-9._:\/-]+$/', $model) !== 1) {
            throw new ValidationException('Ungültiger Modellname.');
        }

        return $model;
    }

    private function validatedLanguage(mixed $value): string
    {
        $language = strtolower(trim((string) (is_scalar($value) ? $value : '')));
        if ($language !== '' && preg_match('/^[a-z]{2}$/', $language) !== 1) {
            throw new ValidationException('Die Sprache muss ein zweistelliger Code sein (z. B. „de") oder leer bleiben.');
        }

        return $language;
    }

    private function validatedBaseUrl(mixed $value): string
    {
        $url = trim((string) (is_scalar($value) ? $value : ''));
        if ($url === '') {
            return self::DEFAULT_BASE_URL;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !str_starts_with($url, 'https://')) {
            throw new ValidationException('Die Adresse des Sprachdienstes muss eine https-URL sein.');
        }

        return rtrim($url, '/');
    }
}
