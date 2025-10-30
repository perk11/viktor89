<?php

namespace Perk11\Viktor89\IPC;

class TaskCompletedMessage extends ChannelMessage
{
    public function __construct(public readonly int $workerId)
    {
    }
}
