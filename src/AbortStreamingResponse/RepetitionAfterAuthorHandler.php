<?php

namespace Perk11\Viktor89\AbortStreamingResponse;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class RepetitionAfterAuthorHandler implements AbortStreamingResponseHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $charactersToCheckInitially = 20,
    ) {

    }
    public function getNewResponse(string $prompt, string $currentResponse): string|false
    {
        $indexOfAuthorEnd = strpos($currentResponse, '] ');
        if ($indexOfAuthorEnd === false) {
            return false;
        }
        if ((mb_strlen($currentResponse) - $indexOfAuthorEnd) < 30) {
            return false;
        }
        if (!$this->isStringStartingToRepeat($prompt . $currentResponse, $this->charactersToCheckInitially)) {
            return false;
        }
        $this->logger->log(LogLevel::INFO, 'Repetition detected, aborting response');
        for (
            $repeatingStringLength = min(
                $this->charactersToCheckInitially,
                mb_strlen($currentResponse) - $indexOfAuthorEnd
            ); $repeatingStringLength >= $this->charactersToCheckInitially; $repeatingStringLength--
        ) {
            if (!$this->isStringStartingToRepeat($prompt . $currentResponse, $repeatingStringLength)) {
                break;
            }
        }

        return mb_substr($currentResponse, 0, -$repeatingStringLength);
    }

    private function isStringStartingToRepeat(string $str, int $charactersToCheck): bool
    {
        if (mb_strlen($str) < $charactersToCheck) {
            return false;  // Not enough characters to perform the check
        }
        $lastCharacters = mb_substr($str, -$charactersToCheck);
        $earlierPart = mb_substr($str, 0, -$charactersToCheck);

        return str_contains($earlierPart, $lastCharacters);
    }

}
