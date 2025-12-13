<?php

namespace Perk11\Viktor89\IPC;

class EchoUpdateCallback implements ProgressUpdateCallback
{
    public function __invoke(string $processor, string $status): void
    {
        echo "Progress update received: $processor - $status\n";
    }
}
