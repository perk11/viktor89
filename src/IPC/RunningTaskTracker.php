<?php

namespace Perk11\Viktor89\IPC;

use Amp\Parallel\Worker\Execution;
use Amp\Sync\ChannelException;
use DateTimeImmutable;
use LogicException;

class RunningTaskTracker
{
    private array $runningTasks = [];

    public function __construct(
        private readonly ChatActionUpdater $chatActionUpdater,
        private readonly DraftUpdater $draftUpdater,
    )
    {
    }

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
                    /** @var TaskUpdateMessage $message */
                    echo date('Y-m-d H:i:s') . " $message->workerId: Task update received from $message->processor: $message->status\n";
                    $existingTask = $this->runningTasks[$message->workerId] ?? null;
                    $chatAction = $message->chatAction ?? $existingTask?->chatAction;
                    $actionAddedTime = $message->chatAction ? new DateTimeImmutable() : $existingTask?->actionAddedTime;

                    $this->runningTasks[$message->workerId] = new RunningTask(
                        $message->processor,
                        $message->status,
                        $existingTask?->startTime ?? new DateTimeImmutable(),
                        $chatAction,
                        $actionAddedTime
                    );

                    $this->chatActionUpdater->updateAction($message->workerId, $chatAction);
                    break;
                case TaskCompletedMessage::class:
                    /** @var TaskCompletedMessage $message */
                    unset($this->runningTasks[$message->workerId]);
                    $this->chatActionUpdater->removeAction($message->workerId);
                    $this->draftUpdater->removeDraft($message->workerId);
                    break;
                case DraftUpdateMessage::class:
                    /** @var DraftUpdateMessage $message */
                    $this->draftUpdater->updateDraft($message->workerId, $message->draftMessage);
                    break;
                case DraftCompletedMessage::class:
                    /** @var DraftCompletedMessage $message */
                    $this->draftUpdater->removeDraft($message->workerId);
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
