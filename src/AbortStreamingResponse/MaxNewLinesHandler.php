<?php

namespace Perk11\Viktor89\AbortStreamingResponse;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class MaxNewLinesHandler implements AbortStreamingResponseHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $maxNewLines,
    ) {
    }

    public function getNewResponse(string $prompt, string $currentResponse): string|false
    {
        if (substr_count($currentResponse, "\n") < $this->maxNewLines) {
            return false;
        }

        $this->logger->log(LogLevel::INFO, 'Max new lines reached');

        return trim(mb_substr($currentResponse, 0, mb_strrpos($currentResponse, "\n")));
    }
}
