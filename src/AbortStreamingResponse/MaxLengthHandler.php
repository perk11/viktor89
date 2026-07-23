<?php

namespace Perk11\Viktor89\AbortStreamingResponse;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class MaxLengthHandler implements AbortStreamingResponseHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $maxResponseLength,
    ) {
    }

    public function getNewResponse(string $prompt, string $currentResponse): string|false
    {
        if (mb_strlen($currentResponse) < $this->maxResponseLength) {
            return false;
        }

        $this->logger->log(LogLevel::INFO, 'Max length reached');
        return mb_substr($currentResponse, 0, $this->maxResponseLength);
    }
}
