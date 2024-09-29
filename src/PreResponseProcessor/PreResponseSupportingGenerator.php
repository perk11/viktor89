<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Perk11\Viktor89\MessageChainProcessor;

interface PreResponseSupportingGenerator
{
    public function addPreResponseProcessor(MessageChainProcessor $preResponseProcessor): void;
}
