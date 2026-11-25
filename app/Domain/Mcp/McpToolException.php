<?php

declare(strict_types=1);

namespace App\Domain\Mcp;

/**
 * Fehler bei der Ausführung eines Werkzeugs, den der MCP-Client als
 * Werkzeugergebnis (`isError: true`) sehen soll - nicht als Protokollfehler.
 * So kann das Modell die Meldung lesen und reagieren (FR-MCP-06).
 */
final class McpToolException extends \RuntimeException
{
}
