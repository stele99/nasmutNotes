<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ServerRequestInterface as Request;

final class RequestIp
{
    /**
     * Gehashte Client-IP (NFR-DSG-04: IP-Adressen werden nur gehasht gespeichert/verwendet).
     */
    public static function hash(Request $request): string
    {
        return hash('sha256', self::resolve($request) . '|' . (Env::get('APP_KEY', '') ?? ''));
    }

    /**
     * Client-IP hinter Cloudflare: CF-Connecting-IP, sonst der erste Eintrag
     * aus X-Forwarded-For, zuletzt REMOTE_ADDR (lokal, ohne Proxy). Nur gültige
     * IP-Adressen werden übernommen.
     */
    public static function resolve(Request $request): string
    {
        $cloudflare = trim($request->getHeaderLine('CF-Connecting-IP'));
        if (self::isIp($cloudflare)) {
            return $cloudflare;
        }

        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if (self::isIp($first)) {
                return $first;
            }
        }

        $remote = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');

        return self::isIp($remote) ? $remote : 'unknown';
    }

    private static function isIp(string $value): bool
    {
        return $value !== '' && filter_var($value, FILTER_VALIDATE_IP) !== false;
    }
}
