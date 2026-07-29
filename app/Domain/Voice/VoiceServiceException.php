<?php

declare(strict_types=1);

namespace App\Domain\Voice;

/**
 * Fehler beim Sprachdienst (OpenAI nicht erreichbar, Schlüssel abgelehnt,
 * unbrauchbare Antwort). Getrennt von ValidationException, weil die Ursache
 * nicht beim Nutzer liegt und der Client anders reagieren soll.
 */
final class VoiceServiceException extends \RuntimeException
{
}
