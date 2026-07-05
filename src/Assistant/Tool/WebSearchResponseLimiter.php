<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool;

/**
 * Limits the size of a web search response (measured in bytes of its JSON
 * representation) by progressively dropping results and truncating the
 * largest strings. Used by web search tool call executors so the data
 * returned to the LLM stays within a configurable bound.
 */
final class WebSearchResponseLimiter
{
    public function __construct(
        private readonly int $maxResponseSizeBytes,
    ) {
        if ($this->maxResponseSizeBytes < 1) {
            throw new \InvalidArgumentException(
                'maxResponseSizeBytes must be an integer greater than 0'
            );
        }
    }

    public function limitResponseSize(array $response): array
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
                'results'   => [],
                'truncated' => true,
                'message'   => 'Response exceeded the configured size limit.',
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
        return strlen(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
