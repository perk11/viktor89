<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool;

class McpToolCallExecutor implements ToolCallExecutorInterface
{
    public function __construct(
        private readonly McpServerConnection $connection,
        private readonly string $toolName
    ) {
    }

    public function executeToolCall(array $arguments): array
    {
        try {
            $result = $this->connection->getClient()->callTool($this->toolName, $arguments);
        } catch (\Throwable) {
            // The child server process may have died since the connection was
            // cached; rebuild it once and retry.
            $this->connection->reconnect();
            $result = $this->connection->getClient()->callTool($this->toolName, $arguments);
        }

        return array_map(static fn($content) => $content->jsonSerialize(), $result->content);
    }
}
