<?php

namespace Perk11\Viktor89\Assistant\Tool;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class OllamaWebSearchToolCallExecutor implements ToolCallExecutorInterface
{
    private readonly Client $client;

    private array $supportedArguments = ['query', 'max_results'];

    public function __construct(private readonly string $ollamaSearchApiKey)
    {
        $this->client = new Client();
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
        $searchQuery = $arguments['query'];
        if (!is_string($arguments['query'])) {
            throw new \InvalidArgumentException('Invalid argument type: query must be a string');
        }
        $maxResults = $arguments['max_results'] ?? 5;
        if (!is_int($maxResults) || $maxResults < 1 || $maxResults > 10) {
            throw new \InvalidArgumentException(
                'Invalid argument value: max_results must be an integer between 1 and 10'
            );
        }

        return $this->searchWebWithOllama($searchQuery, $maxResults);
    }

    private function searchWebWithOllama(string $searchQuery, int $maxResults): array
    {
        try {
            $response = $this->client->post('https://ollama.com/api/web_search', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->ollamaSearchApiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json'    => [
                    'query'       => $searchQuery,
                    'max_results' => $maxResults,
                ],
                'timeout' => 30,
            ]);
        } catch (GuzzleException $exception) {
            throw new \RuntimeException('Web search request failed: ' . $exception->getMessage(), 0, $exception);
        }

        $decodedResponse = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decodedResponse)) {
            throw new \RuntimeException('Failed to decode web search JSON response.');
        }

        return $decodedResponse;
    }
}
