<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool;

use Psr\Log\LoggerInterface;

/**
 * Builds the web search tool chain from configuration, wiring up every
 * available web search provider (Ollama, Z.ai MCP) in order of preference.
 *
 * No single provider is assumed to always be present: each is only included
 * when its API key is configured. The Z.ai MCP client connects lazily on the
 * first real tool call, so wiring the providers up never triggers any network
 * I/O. If a provider fails at call time (e.g. Z.ai is unreachable, or returns
 * an error), the GenericWebSearchToolCallExecutor transparently falls back to
 * the next one.
 *
 * The Z.ai executor factory is injectable so the selection logic can be
 * unit-tested without a real MCP server.
 */
final class WebSearchToolFactory
{
    /** @var callable(string): ZaiWebSearchToolCallExecutor */
    private $zaiFactory;

    public function __construct(
        ?callable $zaiFactory = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->zaiFactory = $zaiFactory ?? fn (string $apiKey) => new ZaiWebSearchToolCallExecutor($apiKey);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function buildFromConfig(array $config): GenericWebSearchToolCallExecutor
    {
        return new GenericWebSearchToolCallExecutor($this->buildProviderList($config), $this->logger);
    }

    /**
     * Build the ordered list of available web search executors.
     *
     * Throws if no provider is configured at all. Otherwise each provider whose
     * API key is present is included.
     *
     * @param array<string, mixed> $config
     * @return list<ToolCallExecutorInterface>
     */
    public function buildProviderList(array $config): array
    {
        $ollamaApiKey = is_string($config['ollamaWebSearchApiKey'] ?? null)
            ? trim($config['ollamaWebSearchApiKey'])
            : '';
        $zAiApiKey = is_string($config['zAiWebSearchApiKey'] ?? null)
            ? trim($config['zAiWebSearchApiKey'])
            : '';

        if ($ollamaApiKey === '' && $zAiApiKey === '') {
            throw new \InvalidArgumentException(
                'No web search provider configured. Set at least one of '
                . '"ollamaWebSearchApiKey" or "zAiWebSearchApiKey" in config.json.'
            );
        }

        $tools = [];

        if ($ollamaApiKey !== '') {
            $tools[] = new OllamaWebSearchToolCallExecutor($ollamaApiKey);
        }

        if ($zAiApiKey !== '') {
            $tools[] = ($this->zaiFactory)($zAiApiKey);
        }

        return $tools;
    }
}
