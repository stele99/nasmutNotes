<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Seiten sollen in ein fremdes, geteiltes Notizbuch wandern. Dabei geht die
 * Eigentümerschaft auf dessen Eigentümer über - das geschieht nur nach
 * ausdrücklicher Bestätigung, gleich über welchen Weg verschoben wird.
 */
final class PageTransferConfirmationRequiredException extends \RuntimeException
{
    public function __construct(public readonly string $ownerName, public readonly string $notebookName)
    {
        parent::__construct(sprintf(
            'Die Seiten gehören nach dem Verschieben nach „%s“ %s. Du behältst den Zugriff nur, solange du Teilnehmer bist.',
            $notebookName,
            $ownerName !== '' ? $ownerName : 'dem Eigentümer des Notizbuchs',
        ));
    }
}
