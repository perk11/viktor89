<?php

namespace Perk11\Viktor89\IPC;

use Perk11\Viktor89\Util\Telegram\ChatAction;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class EchoUpdateCallback implements ProgressUpdateCallback
{
    /** @var array callable[] */
    private array $subscribers = [];

    /**
     * @param int $workerId Worker id attached to the emitted TaskUpdateMessage.
     *                     Echo callbacks are used outside of the worker IPC
     *                     flow (e.g. by the summary task), so this defaults
     *                     to 0 rather than being required.
     */
    public function __construct(private readonly int $workerId = 0, private readonly ?LoggerInterface $logger = null)
    {
    }

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
        $this->logger?->log(LogLevel::INFO, "Progress update received: $processor - $status");
    }
}
