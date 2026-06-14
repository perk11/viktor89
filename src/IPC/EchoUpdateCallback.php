<?php

namespace Perk11\Viktor89\IPC;

use Perk11\Viktor89\Util\Telegram\ChatAction;

class EchoUpdateCallback implements ProgressUpdateCallback
{
    /** @var array callable[] */
    private array $subscribers = [];

    public function subscribe(callable $subscriber): void
    {
        $this->subscribers[] = $subscriber;
    }

    public function __invoke(string $processor, string $status, ?ChatAction $chatAction = null): void
    {
        $taskUpdateMessage = new TaskUpdateMessage($this->workerId, $processor, $status, $chatAction);
        foreach ($this->subscribers as $subscriber) {
            $subscriber($taskUpdateMessage);
        }
        echo "Progress update received: $processor - $status\n";
    }
}
