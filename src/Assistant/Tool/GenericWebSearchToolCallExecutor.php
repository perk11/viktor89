<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Delegates web searches to a list of underlying web search executors,
 * returning the result from the first one that succeeds.
 *
 * Executors are tried in the order they are passed to the constructor, so the
 * preferred/most reliable provider should be listed first. This makes the bot
 * resilient to a single provider being down or rate-limited.
 */
class GenericWebSearchToolCallExecutor implements ToolCallExecutorInterface
{
    /** @var ToolCallExecutorInterface[] */
    private readonly array $searchTools;

    /**
     * @param ToolCallExecutorInterface[] $searchTools Web search executors to try, in order of preference.
     */
    public function __construct(array $searchTools, private readonly ?LoggerInterface $logger = null)
    {
        if ($searchTools === []) {
            throw new \InvalidArgumentException('At least one search tool must be provided');
        }

        foreach ($searchTools as $index => $searchTool) {
            if (!$searchTool instanceof ToolCallExecutorInterface) {
                throw new \InvalidArgumentException(
                    'All search tools must implement '
                    . ToolCallExecutorInterface::class
                    . ", but the tool at index $index does not"
                );
            }
        }

        // Freeze the list as a list (re-indexed) of executors.
        $this->searchTools = array_values($searchTools);
    }

    public function executeToolCall(array $arguments): array
    {
        $exceptions = [];
        foreach ($this->searchTools as $searchTool) {
            try {
                return $searchTool->executeToolCall($arguments);
            } catch (\Throwable $exception) {
                $this->logger?->log(LogLevel::ERROR, "Search tool " . $searchTool::class . " failed: " . $exception->getMessage());
                $exceptions[] = $exception;
            }
        }

        $messages = [];
        foreach ($exceptions as $index => $exception) {
            $messages[] = ($index + 1) . '. ' . $exception->getMessage();
        }

        throw new \RuntimeException(
            'All ' . count($this->searchTools) . ' web search tools failed: ' . implode(' | ', $messages),
            0,
            $exceptions[0] ?? null,
        );
    }
}
