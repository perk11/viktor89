<?php

namespace Perk11\Viktor89\AbortStreamingResponse;

class MaxLengthHandler implements AbortStreamingResponseHandler
{
    public function __construct(private readonly int $maxResponseLength)
    {
    }

    public function getNewResponse(string $prompt, string $currentResponse): string|false
    {
        if (mb_strlen($currentResponse) < $this->maxResponseLength) {
            return false;
        }

        echo "Max length reached\n";
        return mb_substr($currentResponse, 0, $this->maxResponseLength);
    }
}
