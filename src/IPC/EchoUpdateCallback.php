<?php

namespace Perk11\Viktor89\IPC;

use Perk11\Viktor89\Util\Telegram\ChatAction;

class EchoUpdateCallback implements ProgressUpdateCallback
{
    public function __invoke(string $processor, string $status, ?ChatAction $chatAction = null): void
    {
        echo "Progress update received: $processor - $status\n";
    }
}
