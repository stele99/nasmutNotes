<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Support\ValidationException;
use RuntimeException;

/**
 * Fehler des Assistant-Proxy: Connectivity-Probleme zum Anbieter und
 * unlesbare Antworten. Der Controller formt daraus eine OpenAI-förmige
 * Fehlerantwort, damit Clients des Desktop-Assistant sie wie gewohnt
 * behandeln können. Vom Anbieter selbst gelieferte Fehler werden nicht
 * geworfen, sondern unverändert durchgereicht.
 */
final class AssistantServiceException extends RuntimeException
{
    public static function upstreamUnreachable(string $message): self
    {
        return new self('Der KI-Dienst ist nicht erreichbar: ' . $message);
    }

    public static function untranscribable(): self
    {
        return new self('Die Transkription lieferte keine verwertbare Antwort.', 0);
    }

    public static function fromValidation(ValidationException $e): self
    {
        return new self($e->getMessage());
    }
}
