<?php

namespace Perk11\Viktor89\PreResponseProcessor;

interface PreResponseSupportingGenerator
{
    public function addPreResponseProcessor(PreResponseProcessor $preResponseProcessor): void;
}
