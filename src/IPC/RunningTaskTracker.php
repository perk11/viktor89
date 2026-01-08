<?php

namespace Perk11\Viktor89\IPC;

use Amp\Parallel\Worker\Execution;
use Amp\Sync\ChannelException;
use DateTimeImmutable;
use LogicException;

class RunningTaskTracker
{
    private array $runningTasks = [];

    public function receive(Execution $execution): void
    {
        $channel = $execution->getChannel();
        while (true) {
            try {
                $message = $channel->receive();
            } catch (ChannelException) {
//                echo date('Y-m-d H:i:s') . " Finished receiving\n";
                return;
            }
            if (!$message instanceof ChannelMessage) {
                throw new LogicException("Unknown message type received");
            }
            switch (get_class($message)) {
                case TaskUpdateMessage::class:
                    echo  date('Y-m-d H:i:s') . " $message->workerId: Task update received from $message->processor: $message->status\n";
                    $this->runningTasks[$message->workerId] = new RunningTask($message->processor, $message->status, new DateTimeImmutable());
                    break;
                case TaskCompletedMessage::class:
//                    echo  date('Y-m-d H:i:s') . " $message->workerId: Task completed\n";
                    unset($this->runningTasks[$message->workerId]);
                    break;
                case RunningTasksQueryMessage::class:
                    echo date('Y-m-d H:i:s') . " Received tasks report request\n";
                    $channel->send(new RunningTasksReportMessage($this->runningTasks));
                    break;
                default:
                    throw new LogicException("Unknown message type received: " . get_class($message));
            }
        }
    }
}
