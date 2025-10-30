<?php

namespace Perk11\Viktor89\IPC;

interface ProgressUpdateCallback
{
    public function __invoke(string $processor, string $status): void;
}
