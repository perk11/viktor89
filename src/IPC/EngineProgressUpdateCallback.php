<?php

namespace Perk11\Viktor89\IPC;

use Amp\Sync\Channel;
use Perk11\Viktor89\Util\Telegram\ChatAction;

class EngineProgressUpdateCallback implements ProgressUpdateCallback
{
    public bool $wasCalled = false;

    /** @var array callable[] */
    private array $subscribers = [];

    public function __construct(private readonly Channel $channel, private readonly int $workerId)
    {
    }

    public function subscribe(callable $subscriber): void
    {
        $this->subscribers[] = $subscriber;
    }

    public function __invoke(string $processor, string $status, ?ChatAction $chatAction = null): void
    {
        $this->wasCalled = true;
        $taskUpdateMessage = new TaskUpdateMessage($this->workerId, $processor, $status, $chatAction);
        foreach ($this->subscribers as $subscriber) {
            $subscriber($taskUpdateMessage);
        }
        $this->channel->send($taskUpdateMessage);
    }
}
