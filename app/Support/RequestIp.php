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
        $serverParams = $request->getServerParams();
        $ip = (string) ($serverParams['REMOTE_ADDR'] ?? 'unknown');

        return hash('sha256', $ip . '|' . (Env::get('APP_KEY', '') ?? ''));
    }
}
