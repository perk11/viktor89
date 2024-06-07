<?php

namespace Perk11\Viktor89\AbortStreamingResponse;

interface AbortStreamingResponseHandler
{
    public function getNewResponse(string $currentResponse): string|false;
}
