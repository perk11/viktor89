<?php

namespace Perk11\Viktor89;

class MaxStreamingResponseLengthHandler implements AbortStreamingResponseHandler
{
    public function __construct(private readonly int $maxResponseLength)
    {
    }

    public function getNewResponse(string $currentResponse): string|false
    {
        if (mb_strlen($currentResponse) < $this->maxResponseLength) {
            return false;
        }

        return mb_substr($currentResponse, 0, $this->maxResponseLength);
    }
}
