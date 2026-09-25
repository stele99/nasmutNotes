<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Das Konto besitzt Notizbücher, an denen andere teilnehmen. Beim Löschen
 * verschwänden sie auch für diese - deshalb nur nach ausdrücklicher
 * Bestätigung.
 */
final class SharedNotebooksException extends \RuntimeException
{
    public function __construct(public readonly int $count)
    {
        parent::__construct(sprintf(
            'Du teilst %d Notizbuch/Notizbücher mit anderen. Beim Löschen verschwinden sie auch für die '
            . 'Teilnehmer. Lass sie vorher vom Administrator übergeben oder bestätige ausdrücklich.',
            $count,
        ));
    }
}
