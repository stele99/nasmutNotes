<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\User;
use App\Repositories\AiModelCostRepository;
use App\Repositories\AiUsageRepository;
use App\Repositories\AuditLogRepository;
use App\Support\ValidationException;

/**
 * Auswertung des KI-Verbrauchs: eigene Summen für den Nutzer, Gesamtübersicht
 * für den Admin und die Pflege des Modellkosten-Katalogs. Kosten werden nicht
 * gespeichert, sondern aus dem Katalog berechnet - der Admin kann also auch
 * rückwirkend korrigieren.
 */
final class AiUsageService
{
    private const MAX_COST = 1_000_000;

    public function __construct(
        private readonly AiUsageRepository $usage,
        private readonly AiModelCostRepository $costs,
        private readonly AuditLogRepository $auditLog,
    ) {
    }

    /** @return array<string, mixed> */
    public function userSummary(User $user): array
    {
        $summary = $this->usage->summaryForUser($user->id);

        return [
            'total' => $this->windowPayload($summary, 'tokens_total', 'cost_total'),
            'last_30_days' => $this->windowPayload($summary, 'tokens_30d', 'cost_30d'),
            'models' => array_map(
                [$this, 'summaryPayload'],
                $this->usage->summaryForUserByModel($user->id),
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function adminOverview(): array
    {
        $users = array_map(
            function (array $row): array {
                unset($row['model']);

                return $row;
            },
            $this->usage->overviewByUser(),
        );

        $models = array_map(
            [$this, 'summaryPayload'],
            $this->usage->overviewByModel(),
        );

        $totals = [
            'tokens_30d' => array_sum(array_column($users, 'tokens_30d')),
            'tokens_total' => array_sum(array_column($users, 'tokens_total')),
            'cost_30d' => null,
            'cost_total' => null,
        ];
        $priced = array_values(array_filter($users, static fn (array $row): bool => $row['priced']));
        if ($priced !== []) {
            $totals['cost_30d'] = round(array_sum(array_column($priced, 'cost_30d')), 4);
            $totals['cost_total'] = round(array_sum(array_column($priced, 'cost_total')), 4);
            $totals['currency'] = $priced[0]['currency'];
        }

        return ['users' => $users, 'models' => $models, 'totals' => $totals];
    }

    /** @return array<int, array<string, mixed>> */
    public function costs(): array
    {
        return array_map(
            static fn (array $row): array => [
                'model' => (string) $row['model'],
                'input_per_1m' => (float) $row['input_per_1m'],
                'output_per_1m' => (float) $row['output_per_1m'],
                'currency' => (string) $row['currency'],
                'updated_at' => $row['updated_at'],
            ],
            $this->costs->all(),
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array{model: string, input_per_1m: float, output_per_1m: float, currency: string}
     */
    public function setCost(User $admin, array $input, string $ipHash): array
    {
        $model = $this->validatedModel($input['model'] ?? '');
        $inputPer1m = $this->validatedCost($input['input_per_1m'] ?? null, 'Input');
        $outputPer1m = $this->validatedCost($input['output_per_1m'] ?? null, 'Output');

        $currency = strtoupper(trim((string) ($input['currency'] ?? 'EUR')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new ValidationException('Die Währung muss ein dreistelliger Code sein (z. B. EUR).');
        }

        $this->costs->upsert($model, $inputPer1m, $outputPer1m, $currency);
        $this->auditLog->log($admin->id, 'ai_model_cost_changed', null, null, $ipHash, [
            'model' => $model,
            'input_per_1m' => $inputPer1m,
            'output_per_1m' => $outputPer1m,
        ]);

        return [
            'model' => $model,
            'input_per_1m' => (float) $inputPer1m,
            'output_per_1m' => (float) $outputPer1m,
            'currency' => $currency,
        ];
    }

    public function removeCost(User $admin, string $model, string $ipHash): void
    {
        $model = $this->validatedModel($model);
        $this->costs->delete($model);
        $this->auditLog->log($admin->id, 'ai_model_cost_removed', null, null, $ipHash, [
            'model' => $model,
        ]);
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function summaryPayload(array $summary): array
    {
        unset($summary['priced']);

        return $summary;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array{tokens: int, cost: ?float, currency: ?string}
     */
    private function windowPayload(array $summary, string $tokensKey, string $costKey): array
    {
        return [
            'tokens' => $summary[$tokensKey],
            'cost' => $summary[$costKey],
            'currency' => $summary['currency'],
        ];
    }

    private function validatedModel(mixed $value): string
    {
        $model = trim((string) (is_scalar($value) ? $value : ''));
        if ($model === '' || mb_strlen($model) > 100 || preg_match('/^[A-Za-z0-9._:\/-]+$/', $model) !== 1) {
            throw new ValidationException('Ungültiger Modellname.');
        }

        return $model;
    }

    private function validatedCost(mixed $value, string $label): string
    {
        if (is_string($value) && trim($value) !== '') {
            $value = str_replace(',', '.', $value);
        }
        $cost = is_numeric($value) ? (float) $value : null;
        if ($cost === null || $cost < 0 || $cost > self::MAX_COST) {
            throw new ValidationException(
                "Der {$label}-Preis muss eine Zahl zwischen 0 und " . self::MAX_COST . ' sein.',
            );
        }

        return (string) $cost;
    }
}
