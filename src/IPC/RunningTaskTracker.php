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
        private readonly FinalMessageTracker $finalMessageTracker,
    ) {
    }

    public function receive(Execution $execution): void
    {
        ini_set('memory_limit', -1);
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
                case DraftUpdateMessage::class:
                    /** @var DraftUpdateMessage $message */
                    $this->draftUpdater->updateDraft($message->workerId, $message->draft);
                    break;
                case MessageAboutToBeSentMessage::class:
                    /** @var MessageAboutToBeSentMessage $message */
                    echo date('Y-m-d H:i:s') . " $message->workerId: Final message about to be sent in chat $message->chatId, stopping notifications\n";
                    $this->finalMessageTracker->markWorkerFinalizing($message->workerId);
                    $this->chatActionUpdater->removeAction($message->workerId);
                    $this->draftUpdater->removeDraft($message->workerId);
                    $channel->send(new AckMessage());
                    break;
                case TaskCompletedMessage::class:
                    /** @var TaskCompletedMessage $message */
                    unset($this->runningTasks[$message->workerId]);
                    $this->chatActionUpdater->removeAction($message->workerId);
                    $this->draftUpdater->removeDraft($message->workerId);
                    $this->finalMessageTracker->clearWorker($message->workerId);
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
