<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Verbrauchsbuch der KI-Aufrufe: Schreiben je Aufruf, Auswertung für Nutzer
 * (eigenes Budget) und Admin (alle Nutzer). "Letzte 30 Tage" ist ein
 * rollierendes Fenster gegen die UTC-Zeitstempel der Einträge; die Kosten
 * entstehen erst bei der Auswertung aus dem Modellkosten-Katalog. Modelle
 * ohne Katalogeintrag zählen mit, kosten aber nichts (cost bleibt null).
 */
final class AiUsageRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function log(
        int $userId,
        string $feature,
        string $model,
        int $promptTokens,
        int $completionTokens,
        int $totalTokens,
        bool $estimated,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_usage_log
                (user_id, feature, model, prompt_tokens, completion_tokens, total_tokens, estimated, created_at)
             VALUES (:user_id, :feature, :model, :prompt, :completion, :total, :estimated, :now)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'feature' => $feature,
            'model' => $model,
            'prompt' => $promptTokens,
            'completion' => $completionTokens,
            'total' => $totalTokens,
            'estimated' => $estimated ? 1 : 0,
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
    }

    /**
     * Summen eines Nutzers: rollierend letzte 30 Tage und gesamt.
     *
     * @return array<string, mixed>
     */
    public function summaryForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare($this->summarySelect('u.user_id = :user_id'));
        $stmt->execute(['user_id' => $userId, 'cutoff' => $this->cutoff()]);

        return $this->castSummary($stmt->fetch() ?: []);
    }

    /** Aufschlüsselung eines Nutzers je Modell, verbrauchsstärkste zuerst.
     *
     * @return array<int, array<string, mixed>>
     */
    public function summaryForUserByModel(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            $this->summarySelect('u.user_id = :user_id', 'model') . ' ORDER BY tokens_total DESC'
        );
        $stmt->execute(['user_id' => $userId, 'cutoff' => $this->cutoff()]);

        return array_map([$this, 'castSummary'], $stmt->fetchAll());
    }

    /**
     * Verbrauch aller Nutzer für das Admin-Dashboard: je Nutzer Tokens und
     * Kosten (letzte 30 Tage und gesamt), Nutzer ohne Verbrauch bleiben
     * außen vor.
     *
     * @return array<int, array<string, mixed>>
     */
    public function overviewByUser(): array
    {
        $stmt = $this->pdo->prepare(
            $this->summarySelect('1 = 1', 'user_id')
            . ' ORDER BY cost_30d_raw DESC, tokens_30d DESC, cost_total_raw DESC, tokens_total DESC'
        );
        $stmt->execute(['cutoff' => $this->cutoff()]);
        $rows = $stmt->fetchAll();

        $users = $this->usersById();
        $result = [];
        foreach ($rows as $row) {
            $summary = $this->castSummary($row);
            $summary['user_id'] = (int) $row['group_key'];
            $summary['name'] = (string) ($users[$summary['user_id']]['name'] ?? '');
            $summary['email'] = (string) ($users[$summary['user_id']]['email'] ?? '');
            $result[] = $summary;
        }

        return $result;
    }

    /**
     * Verbrauch je Modell über alle Nutzer (letzte 30 Tage und gesamt).
     *
     * @return array<int, array<string, mixed>>
     */
    public function overviewByModel(): array
    {
        $stmt = $this->pdo->prepare(
            $this->summarySelect('1 = 1', 'model') . ' ORDER BY tokens_total DESC'
        );
        $stmt->execute(['cutoff' => $this->cutoff()]);

        return array_map([$this, 'castSummary'], $stmt->fetchAll());
    }

    private function summarySelect(string $where, string $groupByColumn = ''): string
    {
        $selectGroup = $groupByColumn !== '' ? "u.{$groupByColumn} AS group_key," : '';
        $groupClause = $groupByColumn !== '' ? " GROUP BY u.{$groupByColumn}" : '';

        $cost = 'SUM(c.input_per_1m * u.prompt_tokens / 1000000.0'
            . ' + c.output_per_1m * u.completion_tokens / 1000000.0)';

        return 'SELECT'
            . " {$selectGroup}"
            . ' COALESCE(SUM(CASE WHEN u.created_at >= :cutoff THEN u.total_tokens END), 0) AS tokens_30d,'
            . ' COALESCE(SUM(u.total_tokens), 0) AS tokens_total,'
            . ' SUM(CASE WHEN u.created_at >= :cutoff THEN'
            . ' c.input_per_1m * u.prompt_tokens / 1000000.0 + c.output_per_1m * u.completion_tokens / 1000000.0 END) AS cost_30d_raw,'
            . " COALESCE({$cost}, 0.0) AS cost_total_raw,"
            . ' COUNT(c.model) AS priced_models,'
            . ' MIN(c.currency) AS currency'
            . ' FROM ai_usage_log u LEFT JOIN ai_model_costs c ON c.model = u.model'
            . " WHERE {$where}"
            . $groupClause;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function castSummary(array $row): array
    {
        $priced = (int) ($row['priced_models'] ?? 0) > 0;

        return [
            'model' => isset($row['group_key']) ? (string) $row['group_key'] : '',
            'tokens_30d' => (int) ($row['tokens_30d'] ?? 0),
            'tokens_total' => (int) ($row['tokens_total'] ?? 0),
            'cost_30d' => $priced ? round((float) ($row['cost_30d_raw'] ?? 0), 4) : null,
            'cost_total' => $priced ? round((float) ($row['cost_total_raw'] ?? 0), 4) : null,
            'currency' => $priced && isset($row['currency']) ? (string) $row['currency'] : null,
            'priced' => $priced,
        ];
    }

    /** UTC-Zeitstempel im Format der Einträge, 30 Tage zurück. */
    private function cutoff(): string
    {
        return gmdate('Y-m-d\TH:i:s', time() - 30 * 86400) . '.000Z';
    }

    /** @return array<int, array{id: int, name: string, email: string}> */
    private function usersById(): array
    {
        $stmt = $this->pdo->query('SELECT id, name, email FROM users');
        if ($stmt === false) {
            return [];
        }

        /** @var array<int, array{id: int, name: string, email: string}> $rows */
        $rows = $stmt->fetchAll();

        return array_column($rows, null, 'id');
    }
}
