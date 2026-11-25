<?php

declare(strict_types=1);

namespace App\Domain\Mcp;

use App\Domain\User;
use Psr\Log\LoggerInterface;

/**
 * JSON-RPC-Kern des MCP-Servers (FR-MCP-04): Model Context Protocol über
 * "Streamable HTTP", zustandslos. Jede Anfrage trägt den Token selbst, es gibt
 * keine Sitzung und keinen SSE-Strom - Antworten gehen als einfaches JSON
 * zurück, was die Spezifikation ausdrücklich erlaubt.
 */
final class McpServer
{
    /** Neueste zuerst; die erste ist die Vorgabe für unbekannte Versionen. */
    public const SUPPORTED_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    public const SERVER_NAME = 'nasmut-notes';
    public const SERVER_VERSION = '1.0.0';

    private const PARSE_ERROR = -32700;
    private const INVALID_REQUEST = -32600;
    private const METHOD_NOT_FOUND = -32601;
    private const INVALID_PARAMS = -32602;
    private const INTERNAL_ERROR = -32603;

    public function __construct(
        private readonly McpTools $tools,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Antwort auf einen Körper, der kein JSON ist.
     *
     * @return array<string, mixed>
     */
    public static function parseError(): array
    {
        return self::error(null, self::PARSE_ERROR, 'Ungültiges JSON.');
    }

    /**
     * Verarbeitet eine Nachricht oder (ältere Protokollversionen) einen Stapel.
     * `null` bedeutet: nichts zu antworten (nur Benachrichtigungen/Antworten).
     *
     * @return array<int|string, mixed>|null
     */
    public function handle(mixed $payload, McpToken $token, User $user, string $ipHash): ?array
    {
        if (is_array($payload) && array_is_list($payload)) {
            if ($payload === []) {
                return self::error(null, self::INVALID_REQUEST, 'Leerer Stapel.');
            }
            $responses = [];
            foreach ($payload as $message) {
                $response = $this->handleMessage($message, $token, $user, $ipHash);
                if ($response !== null) {
                    $responses[] = $response;
                }
            }

            return $responses === [] ? null : $responses;
        }

        return $this->handleMessage($payload, $token, $user, $ipHash);
    }

    /** @return array<string, mixed>|null */
    private function handleMessage(mixed $message, McpToken $token, User $user, string $ipHash): ?array
    {
        if (!is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0') {
            return self::error(null, self::INVALID_REQUEST, 'Keine gültige JSON-RPC-2.0-Nachricht.');
        }

        $hasId = array_key_exists('id', $message);
        $id = $message['id'] ?? null;
        if ($hasId && !is_int($id) && !is_string($id)) {
            return self::error(null, self::INVALID_REQUEST, 'Ungültige Nachrichten-ID.');
        }

        $method = $message['method'] ?? null;
        if (!is_string($method)) {
            // Antworten des Clients auf Server-Anfragen - dieser Server stellt
            // keine, also gibt es nichts zuzuordnen.
            return $hasId && (array_key_exists('result', $message) || array_key_exists('error', $message))
                ? null
                : self::error($id, self::INVALID_REQUEST, 'Methode fehlt.');
        }

        // Benachrichtigungen (ohne ID) werden nie beantwortet.
        if (!$hasId) {
            return null;
        }

        $params = $message['params'] ?? [];
        if (!is_array($params)) {
            return self::error($id, self::INVALID_PARAMS, 'params muss ein Objekt sein.');
        }

        try {
            return match ($method) {
                'initialize' => self::result($id, $this->initialize($params, $token)),
                'ping' => self::result($id, new \stdClass()),
                'tools/list' => self::result($id, ['tools' => $this->tools->definitions($token)]),
                'tools/call' => $this->callTool($id, $params, $token, $user, $ipHash),
                default => self::error($id, self::METHOD_NOT_FOUND, "Unbekannte Methode: {$method}"),
            };
        } catch (\Throwable $e) {
            $this->logger?->error('MCP-Anfrage fehlgeschlagen', [
                'method' => $method,
                'exception' => $e,
            ]);

            return self::error($id, self::INTERNAL_ERROR, 'Interner Fehler.');
        }
    }

    /**
     * @param array<array-key, mixed> $params
     * @return array<string, mixed>
     */
    private function initialize(array $params, McpToken $token): array
    {
        $requested = $params['protocolVersion'] ?? null;
        $version = is_string($requested) && in_array($requested, self::SUPPORTED_VERSIONS, true)
            ? $requested
            : self::SUPPORTED_VERSIONS[0];

        return [
            'protocolVersion' => $version,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'title' => 'nasmut notes',
                'version' => self::SERVER_VERSION,
            ],
            'instructions' => $this->instructions($token),
        ];
    }

    private function instructions(McpToken $token): string
    {
        $write = match ($token->writeMode) {
            McpToken::WRITE_ALL => 'Du darfst alle Seiten ändern, die der Nutzer selbst bearbeiten darf.',
            McpToken::WRITE_SELECTED => 'Du darfst nur Seiten ändern, die der Nutzer ausdrücklich für MCP'
                . ' freigegeben hat (read_page: page.mcp_can_write).',
            default => 'Dieser Zugang ist nur lesend.',
        };
        $create = $token->canCreate
            ? ' Neue Notizen darfst du mit create_note anlegen.'
            : ' Neue Notizen anlegen ist nicht erlaubt.';

        return 'nasmut notes ist eine Notiz-App mit Notizbüchern und drei Seitentypen: Notiz (Markdown),'
            . ' Aufgabenliste (Kapitel mit Aufgaben) und Logbuch (Einträge mit Spalten). Finde Seiten mit'
            . ' search_pages oder list_pages und lies sie mit read_page. '
            . $write . $create
            . ' Verschlüsselte Notizen sind weder les- noch änderbar.';
    }

    /**
     * @param array<array-key, mixed> $params
     * @return array<string, mixed>
     */
    private function callTool(int|string $id, array $params, McpToken $token, User $user, string $ipHash): array
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return self::error($id, self::INVALID_PARAMS, 'Werkzeugname fehlt.');
        }
        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            return self::error($id, self::INVALID_PARAMS, 'arguments muss ein Objekt sein.');
        }

        try {
            return self::result($id, $this->tools->call($token, $user, $name, $arguments, $ipHash));
        } catch (\InvalidArgumentException $e) {
            return self::error($id, self::INVALID_PARAMS, $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private static function result(int|string $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    private static function error(int|string|null $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
