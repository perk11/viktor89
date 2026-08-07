<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool;

use Closure;
use Mcp\Client;

/**
 * A live connection to one MCP server, shared across all messages handled by a
 * worker and by every {@see McpToolCallExecutor} for that server.
 *
 * Caches both the connected {@see Client} and the result of listTools(), so
 * neither the process-spawn + initialize handshake nor the per-message
 * tools/list call repeats. If the child server process dies, {@see reconnect()}
 * rebuilds the client and clears the tool cache; because all executors reference
 * this same object, they transparently pick up the new client.
 */
final class McpServerConnection
{
    private Client $client;

    /** @var list<mixed>|null */
    private ?array $tools = null;

    /**
     * @param Closure(): Client $connect Builds and connects a fresh client.
     */
    public function __construct(
        private readonly Closure $connect,
    ) {
        $this->client = $connect();
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * The server's tools, fetched once and cached. Returns the cached list on
     * subsequent calls, so there is no per-message tools/list round-trip.
     *
     * @return list<mixed>
     */
    public function getTools(): array
    {
        return $this->tools ??= $this->client->listTools()->tools;
    }

    /**
     * Rebuild the client (spawn + initialize) and forget the cached tool list,
     * e.g. after the previous connection died.
     */
    public function reconnect(): void
    {
        $this->client = ($this->connect)();
        $this->tools = null;
    }
}
