<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Repositories\AiUsageRepository;

/**
 * Schreibt den Tokenverbrauch eines KI-Aufrufs ins Verbrauchsbuch. Die
 * Usage-Angabe des Anbieters hat Vorrang; fehlt sie, wird grob aus der
 * Textlänge geschätzt (etwa 4 Zeichen je Token) und als geschätzt markiert -
 * die Summen bleiben dann schlechter, aber sie bleiben ehrlich.
 */
final class AiUsageRecorder
{
    private const CHARS_PER_TOKEN = 4;

    public function __construct(private readonly AiUsageRepository $usage)
    {
    }

    /**
     * @param array<string, mixed>|null $usage Usage-Objekt der Antwort; für
     *        Chat `prompt_tokens`/`completion_tokens`, für Transkription
     *        alternativ `input_tokens`/`output_tokens`.
     */
    public function record(
        ?AiCallContext $context,
        string $model,
        ?array $usage,
        ?string $estimatedPrompt = null,
        ?string $estimatedCompletion = null,
    ): void {
        if ($context === null) {
            return;
        }

        $prompt = self::positiveInt($usage['prompt_tokens'] ?? null)
            ?? self::positiveInt($usage['input_tokens'] ?? null);
        $completion = self::positiveInt($usage['completion_tokens'] ?? null)
            ?? self::positiveInt($usage['output_tokens'] ?? null);
        $estimated = false;

        if ($usage !== null && is_scalar($usage['total_tokens'] ?? null)) {
            $total = self::positiveInt($usage['total_tokens']);
        } else {
            $total = null;
        }

        if ($prompt === null || $completion === null) {
            $prompt ??= self::estimate($estimatedPrompt);
            $completion ??= self::estimate($estimatedCompletion);
            $estimated = true;
        }

        $this->usage->log(
            $context->userId,
            $context->feature,
            $model,
            $prompt,
            $completion,
            $total ?? $prompt + $completion,
            $estimated,
        );
    }

    private static function positiveInt(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    private static function estimate(?string $text): int
    {
        if ($text === null || $text === '') {
            return 0;
        }

        return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
    }
}
