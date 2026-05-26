<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool;

use Mcp\Client;

class McpToolCallExecutor implements ToolCallExecutorInterface
{
    public function __construct(
        private readonly Client $client,
        private readonly string $toolName
    ) {
    }

    public function executeToolCall(array $arguments): array
    {
        $result = $this->client->callTool($this->toolName, $arguments);
        return array_map(static fn($content) => $content->jsonSerialize(), $result->content);
    }
}
