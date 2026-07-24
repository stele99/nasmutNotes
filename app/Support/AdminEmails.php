<?php

declare(strict_types=1);

namespace App\Support;

final class AdminEmails
{
    /** @var string[] */
    private array $emails;

    public function __construct(string $adminEmailsEnv)
    {
        $this->emails = array_values(array_filter(array_map(
            static fn (string $email): string => strtolower(trim($email)),
            explode(',', $adminEmailsEnv)
        )));
    }

    public function isAdmin(string $email): bool
    {
        return in_array(strtolower(trim($email)), $this->emails, true);
    }
}
