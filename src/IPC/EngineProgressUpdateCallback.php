<?php

namespace Perk11\Viktor89\IPC;

use Amp\Sync\Channel;
use Perk11\Viktor89\Util\Telegram\ChatAction;

class EngineProgressUpdateCallback implements ProgressUpdateCallback
{
    public bool $wasCalled = false;
    public function __construct(private readonly Channel $channel, private readonly int $workerId)
    {
    }
    public function __invoke(string $processor, string $status, ?ChatAction $chatAction = null): void
    {
        $this->wasCalled = true;
        $this->channel->send(new TaskUpdateMessage($this->workerId, $processor, $status, $chatAction));
    }
}
