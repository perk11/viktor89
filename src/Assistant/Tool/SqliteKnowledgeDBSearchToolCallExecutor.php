<?php

namespace Perk11\Viktor89\Assistant\Tool;

class SqliteKnowledgeDBSearchToolCallExecutor implements ToolCallExecutorInterface
{
    private const int DEFAULT_MAX_RESPONSE_SIZE_BYTES = 64000;

    private readonly \PDO $sqliteDatabaseConnection;

    private array $supportedToolCallArguments = ['query', 'max_results'];

    public function __construct(
        private readonly string $absoluteSqliteDatabaseFilePath,
        private readonly int $maximumAllowedResponseSizeBytes = self::DEFAULT_MAX_RESPONSE_SIZE_BYTES,
    ) {
        if ($this->maximumAllowedResponseSizeBytes < 1) {
            throw new \InvalidArgumentException(
                'maximumAllowedResponseSizeBytes must be an integer greater than 0'
            );
        }

        if (!file_exists($this->absoluteSqliteDatabaseFilePath)) {
            throw new \RuntimeException("Database file not found at: " . $this->absoluteSqliteDatabaseFilePath);
        }

        $this->sqliteDatabaseConnection = new \PDO('sqlite:' . $this->absoluteSqliteDatabaseFilePath);
        $this->sqliteDatabaseConnection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function executeToolCall(array $arguments): array
    {
        foreach ($arguments as $currentArgumentName => $currentArgumentValue) {
            if (!in_array($currentArgumentName, $this->supportedToolCallArguments, true)) {
                throw new \InvalidArgumentException("Unsupported argument: $currentArgumentName");
            }
        }

        if (!isset($arguments['query'])) {
            throw new \InvalidArgumentException('Missing required argument: query');
        }

        if (!is_string($arguments['query'])) {
            throw new \InvalidArgumentException('Invalid argument type: query must be a string');
        }

        $userProvidedSearchQuery = $arguments['query'];

        $maximumRequestedResultsCount = $arguments['max_results'] ?? 5;
        if (!is_int($maximumRequestedResultsCount) || $maximumRequestedResultsCount < 1 || $maximumRequestedResultsCount > 10) {
            throw new \InvalidArgumentException(
                'Invalid argument value: max_results must be an integer between 1 and 10'
            );
        }

        return $this->executeFullTextSearchAgainstDatabase($userProvidedSearchQuery, $maximumRequestedResultsCount);
    }

    private function executeFullTextSearchAgainstDatabase(string $sanitizedSearchQuery, int $maximumResultsLimit): array
    {
        try {
            $databaseSearchQueryString = <<<SQL
                SELECT 
                    title, 
                    url, 
                    content
                FROM searchable_documents 
                WHERE searchable_documents MATCH :search_query 
                ORDER BY rank 
                LIMIT :results_limit
            SQL;

            $preparedSqliteStatement = $this->sqliteDatabaseConnection->prepare($databaseSearchQueryString);
            $preparedSqliteStatement->bindValue(':search_query', $sanitizedSearchQuery, \PDO::PARAM_STR);
            $preparedSqliteStatement->bindValue(':results_limit', $maximumResultsLimit, \PDO::PARAM_INT);
            $preparedSqliteStatement->execute();

            $fetchedDatabaseRecordsAsAssociativeArray = $preparedSqliteStatement->fetchAll(\PDO::FETCH_ASSOC);

        } catch (\PDOException $databaseException) {
            throw new \RuntimeException('Database search request failed: ' . $databaseException->getMessage(), 0, $databaseException);
        }

        $standardizedResponseWrapperArray = [
            'results' => $fetchedDatabaseRecordsAsAssociativeArray
        ];

        return $this->enforceMaximumResponseSizeLimit($standardizedResponseWrapperArray);
    }

    private function enforceMaximumResponseSizeLimit(array $unconstrainedResponseArray): array
    {
        if ($this->calculateJsonEncodedSizeInBytes($unconstrainedResponseArray) <= $this->maximumAllowedResponseSizeBytes) {
            return $unconstrainedResponseArray;
        }

        $progressivelySizeConstrainedResponseArray = $unconstrainedResponseArray;

        if (isset($progressivelySizeConstrainedResponseArray['results']) && is_array($progressivelySizeConstrainedResponseArray['results'])) {
            while (
                count($progressivelySizeConstrainedResponseArray['results']) > 0
                && $this->calculateJsonEncodedSizeInBytes($progressivelySizeConstrainedResponseArray) > $this->maximumAllowedResponseSizeBytes
            ) {
                array_pop($progressivelySizeConstrainedResponseArray['results']);
            }
        }

        if ($this->calculateJsonEncodedSizeInBytes($progressivelySizeConstrainedResponseArray) <= $this->maximumAllowedResponseSizeBytes) {
            $progressivelySizeConstrainedResponseArray['truncated'] = true;
            return $progressivelySizeConstrainedResponseArray;
        }

        $progressivelySizeConstrainedResponseArray = $this->iterativelyTruncateDeeplyNestedStrings($progressivelySizeConstrainedResponseArray);

        if ($this->calculateJsonEncodedSizeInBytes($progressivelySizeConstrainedResponseArray) > $this->maximumAllowedResponseSizeBytes) {
            return [
                'results' => [],
                'truncated' => true,
                'message' => 'Response exceeded the configured size limit.',
            ];
        }

        $progressivelySizeConstrainedResponseArray['truncated'] = true;

        return $progressivelySizeConstrainedResponseArray;
    }

    private function iterativelyTruncateDeeplyNestedStrings(array $arrayRequiringStringTruncation): array
    {
        $currentlyTruncatedArrayState = $arrayRequiringStringTruncation;

        while ($this->calculateJsonEncodedSizeInBytes($currentlyTruncatedArrayState) > $this->maximumAllowedResponseSizeBytes) {
            $pathToLongestStringInArray = $this->locatePathToLargestStringNode($currentlyTruncatedArrayState);

            if ($pathToLongestStringInArray === null) {
                break;
            }

            $longestDiscoveredStringValue = $this->extractValueFromNestedArrayUsingPath($currentlyTruncatedArrayState, $pathToLongestStringInArray);

            if (!is_string($longestDiscoveredStringValue) || $longestDiscoveredStringValue === '') {
                break;
            }

            $halvedStringLengthCalculation = max(0, intdiv(mb_strlen($longestDiscoveredStringValue, 'UTF-8'), 2) - 1);
            $newTruncatedStringReplacement = $halvedStringLengthCalculation > 0
                ? mb_substr($longestDiscoveredStringValue, 0, $halvedStringLengthCalculation, 'UTF-8') . '...'
                : '';

            $this->injectValueIntoNestedArrayUsingPath($currentlyTruncatedArrayState, $pathToLongestStringInArray, $newTruncatedStringReplacement);
        }

        return $currentlyTruncatedArrayState;
    }

    private function locatePathToLargestStringNode(array $multidimensionalArrayToInspect): ?array
    {
        $pathToLargestDiscoveredString = null;
        $characterLengthOfLargestDiscoveredString = -1;

        $recursiveArrayWalkerClosure = function (mixed $currentlyInspectedValue, array $pathNavigatedSoFar) use (&$recursiveArrayWalkerClosure, &$pathToLargestDiscoveredString, &$characterLengthOfLargestDiscoveredString): void {
            if (is_array($currentlyInspectedValue)) {
                foreach ($currentlyInspectedValue as $currentArrayKey => $nestedArrayValue) {
                    $recursiveArrayWalkerClosure($nestedArrayValue, [...$pathNavigatedSoFar, $currentArrayKey]);
                }

                return;
            }

            if (!is_string($currentlyInspectedValue)) {
                return;
            }

            $currentlyInspectedStringLength = mb_strlen($currentlyInspectedValue, 'UTF-8');
            if ($currentlyInspectedStringLength > $characterLengthOfLargestDiscoveredString) {
                $characterLengthOfLargestDiscoveredString = $currentlyInspectedStringLength;
                $pathToLargestDiscoveredString = $pathNavigatedSoFar;
            }
        };

        $recursiveArrayWalkerClosure($multidimensionalArrayToInspect, []);

        return $pathToLargestDiscoveredString;
    }

    private function extractValueFromNestedArrayUsingPath(array $targetMultidimensionalArray, array $pathKeysToTraverse): mixed
    {
        $currentlyReferencedValue = $targetMultidimensionalArray;

        foreach ($pathKeysToTraverse as $currentPathKeySegment) {
            if (!is_array($currentlyReferencedValue) || !array_key_exists($currentPathKeySegment, $currentlyReferencedValue)) {
                return null;
            }

            $currentlyReferencedValue = $currentlyReferencedValue[$currentPathKeySegment];
        }

        return $currentlyReferencedValue;
    }

    private function injectValueIntoNestedArrayUsingPath(array &$targetMultidimensionalArray, array $pathKeysToTraverse, mixed $replacementValueToInject): void
    {
        $currentlyNavigatedArrayReference = &$targetMultidimensionalArray;

        foreach ($pathKeysToTraverse as $currentPathKeySegment) {
            if (!is_array($currentlyNavigatedArrayReference) || !array_key_exists($currentPathKeySegment, $currentlyNavigatedArrayReference)) {
                return;
            }

            $currentlyNavigatedArrayReference = &$currentlyNavigatedArrayReference[$currentPathKeySegment];
        }

        $currentlyNavigatedArrayReference = $replacementValueToInject;
    }

    private function calculateJsonEncodedSizeInBytes(array $arrayToBeMeasured): int
    {
        return strlen(json_encode($arrayToBeMeasured, JSON_THROW_ON_ERROR));
    }
}
