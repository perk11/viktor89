<?php

namespace Perk11\Viktor89\IPC;

use Perk11\Viktor89\Util\Telegram\ChatAction;

interface ProgressUpdateCallback
{
    public function __invoke(string $processor, string $status, ?ChatAction $chatAction = null): void;
}
