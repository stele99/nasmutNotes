<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Modellkosten-Katalog (Euro pro 1 Mio. Tokens, Input und Output getrennt).
 * Der Admin pflegt hier jedes Modell - auch die bereits laufenden der
 * Sprachnotizen und der Notiz-KI. Die Verrechnung geschieht zur Anzeigezeit,
 * nachträgliche Korrekturen bewerten die Historie also neu.
 */
final class AiModelCostRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $stmt = $this->pdo->query(
            'SELECT model, input_per_1m, output_per_1m, currency, updated_at
             FROM ai_model_costs ORDER BY model'
        );
        if ($stmt === false) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll();

        return $rows;
    }

    public function upsert(string $model, string $inputPer1m, string $outputPer1m, string $currency): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_model_costs (model, input_per_1m, output_per_1m, currency, updated_at)
             VALUES (:model, :input, :output, :currency, :now)
             ON CONFLICT(model) DO UPDATE SET
                input_per_1m = excluded.input_per_1m,
                output_per_1m = excluded.output_per_1m,
                currency = excluded.currency,
                updated_at = excluded.updated_at'
        );
        $stmt->execute([
            'model' => $model,
            'input' => $inputPer1m,
            'output' => $outputPer1m,
            'currency' => $currency,
            'now' => gmdate('Y-m-d\TH:i:s.v\Z'),
        ]);
    }

    public function delete(string $model): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ai_model_costs WHERE model = :model');
        $stmt->execute(['model' => $model]);
    }
}
