<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool;

use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Level;
use Monolog\Logger;

/**
 * Performs web searches through the Z.ai web search MCP server.
 *
 * This executor talks to the
 * remote MCP server exposed at https://api.z.ai/api/mcp/web_search_prime/mcp,
 * configured with an `Authorization: Bearer <api_key>` header (see
 * https://docs.z.ai/guides/tools/web-search).
 *
 * The MCP client is connected lazily on the first actual tool call, so simply
 * wiring this executor up (which happens for every processed message) never
 * triggers a network round-trip unless the model really needs a web search.
 */
class ZaiWebSearchToolCallExecutor implements ToolCallExecutorInterface
{
    private const int DEFAULT_MAX_RESPONSE_SIZE_BYTES = 64000;

    /** @see https://docs.z.ai/guides/tools/web-search */
    private const string ZAI_WEB_SEARCH_MCP_ENDPOINT = 'https://api.z.ai/api/mcp/web_search_prime/mcp';

    private const string ZAI_DEFAULT_TOOL_NAME = 'web_search_prime';

    /**
     * The Z.ai web search MCP tool reads the query from the `search_query`
     * argument (it rejects the call with "search_query cannot be empty" if it
     * is missing), so we map the shared tool's `query` argument to it.
     */
    private const string ZAI_SEARCH_PARAMETER_NAME = 'search_query';

    private readonly WebSearchResponseLimiter $responseLimiter;

    /** @var \Closure(): Client Lazily builds (and caches) a connected MCP client. */
    private readonly \Closure $clientProvider;

    private ?Client $client = null;

    /** @var string[] */
    private array $supportedArguments = ['query', 'max_results'];

    /**
     * @param \Closure(): Client|null $clientProvider Builds a connected MCP
     *        client. Defaults to a provider that connects to the Z.ai web
     *        search MCP endpoint with the given API key. Inject a custom
     *        provider (e.g. one returning a mock client) for testing.
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $endpoint = self::ZAI_WEB_SEARCH_MCP_ENDPOINT,
        private readonly string $toolName = self::ZAI_DEFAULT_TOOL_NAME,
        private readonly int $maxResponseSizeBytes = self::DEFAULT_MAX_RESPONSE_SIZE_BYTES,
        ?\Closure $clientProvider = null,
    ) {
        if (trim($this->apiKey) === '') {
            throw new \InvalidArgumentException('zAiSearchApiKey cannot be empty');
        }

        $this->responseLimiter = new WebSearchResponseLimiter($this->maxResponseSizeBytes);
        $this->clientProvider = $clientProvider ?? $this->createDefaultClientProvider();
    }

    public function executeToolCall(array $arguments): array
    {
        foreach ($arguments as $argumentName => $argumentValue) {
            if (!in_array($argumentName, $this->supportedArguments, true)) {
                throw new \InvalidArgumentException("Unsupported argument: $argumentName");
            }
        }

        if (!isset($arguments['query'])) {
            throw new \InvalidArgumentException('Missing required argument: query');
        }

        if (!is_string($arguments['query'])) {
            throw new \InvalidArgumentException('Invalid argument type: query must be a string');
        }

        $searchQuery = $arguments['query'];

        // The MCP tool does not take a result-count parameter; we cap the number
        // of results client-side after normalizing the response. We still accept
        // the argument for compatibility with the shared web_search tool definition.
        $maxResults = $arguments['max_results'] ?? 5;
        if (!is_int($maxResults) || $maxResults < 1 || $maxResults > 10) {
            throw new \InvalidArgumentException(
                'Invalid argument value: max_results must be an integer between 1 and 10'
            );
        }

        $result = $this->callZaiTool($searchQuery);

        return $this->responseLimiter->limitResponseSize(
            $this->normalizeMcpResult($result, $maxResults)
        );
    }

    /**
     * Call the Z.ai MCP web search tool, connecting to the server on first use.
     * Both connection and tool-call failures (including MCP-level errors) are
     * converted to a RuntimeException so a GenericWebSearchToolCallExecutor can
     * transparently fall back to another provider.
     */
    private function callZaiTool(string $searchQuery): CallToolResult
    {
        try {
            $result = $this->getClient()->callTool(
                $this->toolName,
                [self::ZAI_SEARCH_PARAMETER_NAME => $searchQuery],
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException('Z.ai MCP web search request failed: ' . $e->getMessage(), 0, $e);
        }

        if ($result->isError) {
            throw new \RuntimeException(
                'Z.ai MCP web search returned an error: ' . $this->extractErrorText($result)
            );
        }

        return $result;
    }

    private function getClient(): Client
    {
        // Lazy connection: the MCP client is only created (and the server only
        // contacted) the first time a web search is actually performed, then
        // reused for subsequent calls within the same executor instance.
        return $this->client ??= ($this->clientProvider)();
    }

    private function createDefaultClientProvider(): \Closure
    {
        $apiKey = $this->apiKey;
        $endpoint = $this->endpoint;

        return function () use ($apiKey, $endpoint): Client {
            $transport = new HttpTransport(
                endpoint: $endpoint,
                headers:  ['Authorization' => 'Bearer ' . $apiKey],
            );

            $logger = new Logger(
                'zai-mcp-web-search',
                [new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, Level::Debug)],
            );

            $client = Client::builder()
                ->setClientInfo('Viktor89', '1.0.0')
                ->setLogger($logger)
                ->build();
            $client->connect($transport);

            return $client;
        };
    }

    /**
     * Normalize the MCP tool result into the same shape used by the other web
     * search executors: `['results' => [['title', 'url', 'content'], ...]]`.
     *
     * The Z.ai MCP web search returns its results as text content. When that
     * text is valid JSON we parse it and map each entry's `link` to `url`.
     * Otherwise we wrap the raw text so the LLM still receives the content.
     */
    private function normalizeMcpResult(CallToolResult $result, int $maxResults): array
    {
        $structured = $result->structuredContent;
        if (is_array($structured)) {
            $fromStructured = $this->normalizeResultList($structured['results'] ?? $structured, $maxResults);
            if ($fromStructured !== []) {
                return ['results' => $fromStructured];
            }
        }

        $text = trim(implode("\n", array_filter(
            array_map(
                static fn ($content): string => $content instanceof TextContent ? (string) $content->text : '',
                $result->content,
            ),
        )));

        if ($text !== '') {
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                $normalized = $this->normalizeResultList($decoded, $maxResults);
                if ($normalized !== []) {
                    return ['results' => $normalized];
                }
            }

            // Not structured JSON: surface the raw text so the model can still use it.
            return ['results' => [['title' => null, 'url' => null, 'content' => $text]]];
        }

        return ['results' => []];
    }

    /**
     * @param mixed $items Expected to be a list of result arrays.
     * @return array<int, array{title: mixed, url: mixed, content: mixed}>
     */
    private function normalizeResultList(mixed $items, int $maxResults): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $normalized[] = [
                'title'   => $item['title'] ?? null,
                'url'     => $item['link'] ?? $item['url'] ?? null,
                'content' => $item['content'] ?? $item['snippet'] ?? null,
            ];
        }

        if (count($normalized) > $maxResults) {
            $normalized = array_slice($normalized, 0, $maxResults);
        }

        return $normalized;
    }

    private function extractErrorText(CallToolResult $result): string
    {
        foreach ($result->content as $content) {
            if ($content instanceof TextContent && is_string($content->text) && $content->text !== '') {
                return $content->text;
            }
        }

        return '(no error details provided)';
    }
}
