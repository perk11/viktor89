<?php

namespace Perk11\Viktor89\Assistant\Tool;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class OllamaWebSearchToolCallExecutor implements ToolCallExecutorInterface
{
    private const int DEFAULT_MAX_RESPONSE_SIZE_BYTES = 16384;

    private readonly Client $client;

    private array $supportedArguments = ['query', 'max_results'];

    public function __construct(
        private readonly string $ollamaSearchApiKey,
        private readonly int $maxResponseSizeBytes = self::DEFAULT_MAX_RESPONSE_SIZE_BYTES,
    ) {
        if ($this->maxResponseSizeBytes < 1) {
            throw new \InvalidArgumentException(
                'maxResponseSizeBytes must be an integer greater than 0'
            );
        }

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

        if (!is_string($arguments['query'])) {
            throw new \InvalidArgumentException('Invalid argument type: query must be a string');
        }

        $searchQuery = $arguments['query'];

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
                'json' => [
                    'query'       => $searchQuery,
                    'max_results' => $maxResults,
                ],
                'timeout' => 30,
            ]);
        } catch (GuzzleException $exception) {
            throw new \RuntimeException('Web search request failed: ' . $exception->getMessage(), 0, $exception);
        }

        $decodedResponse = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decodedResponse)) {
            throw new \RuntimeException('Failed to decode web search JSON response.');
        }

        return $this->limitResponseSize($decodedResponse);
    }

    private function limitResponseSize(array $response): array
    {
        if ($this->getJsonSizeInBytes($response) <= $this->maxResponseSizeBytes) {
            return $response;
        }

        $limitedResponse = $response;

        if (isset($limitedResponse['results']) && is_array($limitedResponse['results'])) {
            while (
                count($limitedResponse['results']) > 0
                && $this->getJsonSizeInBytes($limitedResponse) > $this->maxResponseSizeBytes
            ) {
                array_pop($limitedResponse['results']);
            }
        }

        if ($this->getJsonSizeInBytes($limitedResponse) <= $this->maxResponseSizeBytes) {
            $limitedResponse['truncated'] = true;
            return $limitedResponse;
        }

        $limitedResponse = $this->truncateNestedStringsToFit($limitedResponse);

        if ($this->getJsonSizeInBytes($limitedResponse) > $this->maxResponseSizeBytes) {
            return [
                'results' => [],
                'truncated' => true,
                'message' => 'Response exceeded the configured size limit.',
            ];
        }

        $limitedResponse['truncated'] = true;

        return $limitedResponse;
    }

    private function truncateNestedStringsToFit(array $value): array
    {
        $truncatedValue = $value;

        while ($this->getJsonSizeInBytes($truncatedValue) > $this->maxResponseSizeBytes) {
            $largestStringPath = $this->findLargestStringPath($truncatedValue);

            if ($largestStringPath === null) {
                break;
            }

            $currentString = $this->getValueAtPath($truncatedValue, $largestStringPath);

            if (!is_string($currentString) || $currentString === '') {
                break;
            }

            $newLength = max(0, intdiv(mb_strlen($currentString, 'UTF-8'), 2) - 1);
            $replacement = $newLength > 0
                ? mb_substr($currentString, 0, $newLength, 'UTF-8') . '...'
                : '';

            $this->setValueAtPath($truncatedValue, $largestStringPath, $replacement);
        }

        return $truncatedValue;
    }

    private function findLargestStringPath(array $value): ?array
    {
        $largestPath = null;
        $largestLength = -1;

        $walker = function (mixed $currentValue, array $currentPath) use (&$walker, &$largestPath, &$largestLength): void {
            if (is_array($currentValue)) {
                foreach ($currentValue as $key => $nestedValue) {
                    $walker($nestedValue, [...$currentPath, $key]);
                }

                return;
            }

            if (!is_string($currentValue)) {
                return;
            }

            $currentLength = mb_strlen($currentValue, 'UTF-8');
            if ($currentLength > $largestLength) {
                $largestLength = $currentLength;
                $largestPath = $currentPath;
            }
        };

        $walker($value, []);

        return $largestPath;
    }

    private function getValueAtPath(array $value, array $path): mixed
    {
        $currentValue = $value;

        foreach ($path as $pathSegment) {
            if (!is_array($currentValue) || !array_key_exists($pathSegment, $currentValue)) {
                return null;
            }

            $currentValue = $currentValue[$pathSegment];
        }

        return $currentValue;
    }

    private function setValueAtPath(array &$value, array $path, mixed $replacementValue): void
    {
        $currentReference = &$value;

        foreach ($path as $pathSegment) {
            if (!is_array($currentReference) || !array_key_exists($pathSegment, $currentReference)) {
                return;
            }

            $currentReference = &$currentReference[$pathSegment];
        }

        $currentReference = $replacementValue;
    }

    private function getJsonSizeInBytes(array $value): int
    {
        return strlen(json_encode($value, JSON_THROW_ON_ERROR));
    }
}
