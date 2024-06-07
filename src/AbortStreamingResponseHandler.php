<?php

namespace Perk11\Viktor89;

interface AbortStreamingResponseHandler
{
    public function getNewResponse(string $currentResponse): string|false;
}
