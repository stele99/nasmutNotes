<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use Psr\Http\Message\StreamInterface;

/**
 * Antwort des KI-Dienstes: Status und Content-Type werden unverändert
 * durchgereicht, der Body als Stream. Beim Streaming ist das ein Tee-Stream,
 * der nebenbei die Token-Nutzung mitliest.
 */
final readonly class UpstreamReply
{
    public function __construct(
        public int $status,
        public string $contentType,
        public StreamInterface $body,
    ) {
    }
}
