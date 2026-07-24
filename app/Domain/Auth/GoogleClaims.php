<?php

declare(strict_types=1);

namespace App\Domain\Auth;

final class GoogleClaims
{
    public function __construct(
        public readonly string $sub,
        public readonly string $email,
        public readonly bool $emailVerified,
        public readonly string $name,
        public readonly ?string $picture,
        public readonly ?string $hostedDomain,
    ) {
    }
}
