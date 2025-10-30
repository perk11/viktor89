<?php

namespace Perk11\Viktor89\IPC;

class TaskUpdateMessage extends ChannelMessage
{
    public function __construct(public readonly int $workerId, public readonly string $processor, public readonly string $status)
    {
    }
}
