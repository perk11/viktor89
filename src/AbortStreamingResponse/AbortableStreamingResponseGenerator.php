<?php

namespace Perk11\Viktor89\AbortStreamingResponse;

interface AbortableStreamingResponseGenerator
{
    public function addAbortResponseHandler(AbortStreamingResponseHandler $abortResponseHandler): void;
}
