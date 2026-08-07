<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool;

use Closure;

/**
 * Caches {@see McpServerConnection} instances within a worker process so each
 * MCP server is spawned and initialized at most once per server configuration.
 *
 * Amp workers are long-lived and reused across messages, so a static cache
 * survives between messages handled by the same worker. Each worker keeps its
 * own cache (separate process). The cached connection keeps its child server
 * process alive for reuse; when the worker exits, stdin closes and the child
 * shuts down on its own.
 */
final class McpConnectionCache
{
    /** @var array<string, McpServerConnection> */
    private static array $connections = [];

    /**
     * @param string                             $key     Stable identifier for the server configuration.
     * @param Closure(): McpServerConnection     $factory Builds a fresh connection (only invoked on a miss).
     */
    public static function get(string $key, Closure $factory): McpServerConnection
    {
        return self::$connections[$key] ??= $factory();
    }

    /**
     * Drop a cached connection so the next {@see get()} rebuilds it.
     */
    public static function invalidate(string $key): void
    {
        unset(self::$connections[$key]);
    }

    /** Drop every cached connection. Mainly useful in tests to isolate state. */
    public static function clear(): void
    {
        self::$connections = [];
    }
}
