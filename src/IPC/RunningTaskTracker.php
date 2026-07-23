<?php

namespace Perk11\Viktor89\IPC;

use Amp\Parallel\Worker\Execution;
use Amp\Sync\ChannelException;
use DateTimeImmutable;
use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class RunningTaskTracker
{
    private array $runningTasks = [];

    public function __construct(
        private readonly ChatActionUpdater $chatActionUpdater,
        private readonly DraftUpdater $draftUpdater,
        private readonly FinalMessageTracker $finalMessageTracker,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function receive(Execution $execution): void
    {
        ini_set('memory_limit', -1);
        $channel = $execution->getChannel();
        // One channel carries messages for exactly one worker. Remember its id so
        // that, if the channel closes abruptly, we can tear that worker's state
        // down (see the ChannelException handler below).
        $workerId = null;
        while (true) {
            try {
                $message = $channel->receive();
            } catch (ChannelException $exception) {
                // This fires on every task, not just crashes: the per-task JobChannel
                // closes as soon as the worker's run() returns, so the next receive()
                // throws. A genuine crash (OOM kill, segfault, fatal error) is surfaced
                // separately by the Execution future's catch handler in viktor89.php,
                // so this itself is not an error.
                $this->logger->log(LogLevel::DEBUG, "Worker $workerId channel closed: " . $exception->getMessage());
                // Still the correct teardown either way. Without this, on a real crash
                // (where no TaskCompletedMessage is sent) the draft refresh timer /
                // deferred edit flush would keep firing on behalf of a dead worker, so
                // a stale draft or edit could appear after later messages — and, because
                // its workerDrafts entry is never removed, block cleanupChat for the
                // whole chat for every later worker too.
                if ($workerId !== null) {
                    $this->abortWorker($workerId);
                }
                return;
            }
            if (!$message instanceof ChannelMessage) {
                throw new LogicException("Unknown message type received");
            }
            if (property_exists($message, 'workerId')) {
                $workerId = $message->workerId;
            }
            switch (get_class($message)) {
                case TaskUpdateMessage::class:
                    /** @var TaskUpdateMessage $message */
                    $this->logger->log(LogLevel::DEBUG, "$message->workerId: Task update received from $message->processor: $message->status");
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
                    $this->logger->log(LogLevel::INFO, "$message->workerId: Final message about to be sent in chat $message->chatId, stopping notifications");
                    $this->finalMessageTracker->markWorkerFinalizing($message->workerId);
                    $this->chatActionUpdater->removeAction($message->workerId);
                    $this->draftUpdater->removeDraft($message->workerId);
                    $channel->send(new AckMessage());
                    break;
                case TaskCompletedMessage::class:
                    /** @var TaskCompletedMessage $message */
                    unset($this->runningTasks[$message->workerId]);
                    $this->chatActionUpdater->removeAction($message->workerId);
                    $this->draftUpdater->deliverAndRemoveDraft($message->workerId);
                    $this->finalMessageTracker->clearWorker($message->workerId);
                    break;
                case RunningTasksQueryMessage::class:
                    $this->logger->log(LogLevel::DEBUG, 'Received tasks report request');
                    $channel->send(new RunningTasksReportMessage($this->runningTasks));
                    break;
                default:
                    throw new LogicException("Unknown message type received: " . get_class($message));
            }
        }
    }

    /**
     * Tear down a worker that disappeared without completing normally: stop its
     * typing timer and cancel its draft refresh / deferred edit flush. Nothing is
     * sent on the worker's behalf — a crashed worker should not emit anything new.
     */
    private function abortWorker(int $workerId): void
    {
        unset($this->runningTasks[$workerId]);
        $this->chatActionUpdater->removeAction($workerId);
        $this->draftUpdater->removeDraft($workerId);
        $this->finalMessageTracker->clearWorker($workerId);
    }
}
