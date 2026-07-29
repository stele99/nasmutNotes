<?php

declare(strict_types=1);

namespace App\Domain\Log;

use App\Support\ValidationException;

/**
 * Spaltenarten einer Logbuch-Seite (FR-LOG-03). Die Datums- und Uhrzeitspalte
 * jedes Eintrags ist keine davon: Sie steckt fest in `log_entries.occurred_at`.
 */
enum LogColumnType: string
{
    case Text = 'text';
    case Location = 'location';
    case Time = 'time';
    case Hours = 'hours';
    case Number = 'number';
    case Money = 'money';
    case User = 'user';

    public static function fromInput(mixed $value): self
    {
        $type = is_string($value) ? self::tryFrom($value) : null;
        if ($type === null) {
            throw new ValidationException('Ungültige Spaltenart.');
        }

        return $type;
    }

    /** Zahlenspalten lassen sich summieren und numerisch sortieren. */
    public function isNumeric(): bool
    {
        return match ($this) {
            self::Hours, self::Number, self::Money => true,
            default => false,
        };
    }

    /** Bezeichnung, die dem Sprachmodell vorgelegt wird (FR-LOG-08). */
    public function promptName(): string
    {
        return match ($this) {
            self::Text => 'text',
            self::Location => 'standort',
            self::Time => 'uhrzeit',
            self::Hours => 'stunden',
            self::Number => 'zahl',
            self::Money => 'betrag',
            self::User => 'benutzer',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Location => 'Standort',
            self::Time => 'Uhrzeit',
            self::Hours => 'Stunden',
            self::Number => 'Zahl',
            self::Money => 'Betrag',
            self::User => 'User',
        };
    }
}
