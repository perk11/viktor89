<?php

namespace Perk11\Viktor89\IPC;

class RunningTasksReportMessage extends ChannelMessage
{
    /** @var RunningTask[] $runningTasks */
    public function __construct(public readonly array $runningTasks)
    {
    }
}
