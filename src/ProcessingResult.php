<?php

namespace Perk11\Viktor89;

class ProcessingResult
{
    public function __construct(public readonly ?InternalMessage $response, public readonly bool $abortProcessing)
    {
    }
}
