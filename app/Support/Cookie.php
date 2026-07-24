<?php

declare(strict_types=1);

namespace App\Support;

final class Cookie
{
    public static function build(
        string $name,
        string $value,
        int $maxAgeSeconds,
        bool $secure,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
        string $path = '/',
    ): string {
        $attrs = [
            $name . '=' . rawurlencode($value),
            'Path=' . $path,
            'Max-Age=' . $maxAgeSeconds,
            'SameSite=' . $sameSite,
        ];

        if ($httpOnly) {
            $attrs[] = 'HttpOnly';
        }
        if ($secure) {
            $attrs[] = 'Secure';
        }

        return implode('; ', $attrs);
    }

    public static function expire(string $name, bool $secure, string $path = '/'): string
    {
        return self::build($name, '', -1, $secure, true, 'Lax', $path);
    }
}
