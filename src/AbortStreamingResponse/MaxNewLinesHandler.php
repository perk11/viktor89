<?php

namespace Perk11\Viktor89\AbortStreamingResponse;

class MaxNewLinesHandler implements AbortStreamingResponseHandler
{
    public function __construct(private readonly int $maxNewLines)
    {
    }

    public function getNewResponse(string $prompt, string $currentResponse): string|false
    {
        if (substr_count($currentResponse, "\n") < $this->maxNewLines) {
            return false;
        }

        echo "Max new lines reached\n";

        return trim(mb_substr($currentResponse, 0, mb_strrpos($currentResponse, "\n")));
    }
}
