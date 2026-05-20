<?php

namespace Perk11\Viktor89\IPC;

use Perk11\Viktor89\Util\Telegram\ChatAction;

class TaskUpdateMessage extends ChannelMessage
{
    public function __construct(
        public readonly int $workerId,
        public readonly string $processor,
        public readonly string $status,
        public readonly ?ChatAction $chatAction = null,
    )
    {
    }
}
