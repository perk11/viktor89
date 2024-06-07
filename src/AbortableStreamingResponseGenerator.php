<?php

namespace Perk11\Viktor89;

interface AbortableStreamingResponseGenerator
{
    public function addAbortResponseHandler(AbortStreamingResponseHandler $abortResponseHandler): void;
}
