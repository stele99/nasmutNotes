<?php

declare(strict_types=1);

namespace App\Support;

final class UrlValidator
{
    public const MAX_LENGTH = 2048;

    public static function isValidHttpUrl(string $url): bool
    {
        if ($url === '' || mb_strlen($url) > self::MAX_LENGTH) {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        return in_array(strtolower($parts['scheme']), ['http', 'https'], true);
    }
}
