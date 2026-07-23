<?php

namespace Perk11\Viktor89\IPC;

use Longman\TelegramBot\Request;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Perk11\Viktor89\Util\Telegram\ChatAction;
use Revolt\EventLoop;

class ChatActionUpdater
{
    /** @var array<int, string> */
    private array $chatActionTimers = [];

    /** @var array<int, ChatAction> */
    private array $workerChatActions = [];

    public function __construct(
        private readonly FinalMessageTracker $finalMessageTracker,
        private readonly float $actionIntervalSeconds = 4,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function updateAction(int $workerIdentifier, ?ChatAction $chatActionToUpdate): void
    {
        if ($this->finalMessageTracker->isFinalMessageBeingSentByWorker($workerIdentifier)) {
            return;
        }

        if ($chatActionToUpdate === null) {
            $this->removeAction($workerIdentifier);
            return;
        }

        $previouslyAssignedAction = $this->workerChatActions[$workerIdentifier] ?? null;
        if ($previouslyAssignedAction !== null && $previouslyAssignedAction->chatId !== $chatActionToUpdate->chatId) {
            $this->removeAction($workerIdentifier);
        }

        $targetChatIdentifier = $chatActionToUpdate->chatId;
        $this->workerChatActions[$workerIdentifier] = $chatActionToUpdate;

        if (!isset($this->chatActionTimers[$targetChatIdentifier])) {
            $this->startChatActionTimer($targetChatIdentifier);
        }
    }

    public function removeAction(int $workerIdentifier): void
    {
        $chatActionToRemove = $this->workerChatActions[$workerIdentifier] ?? null;
        if ($chatActionToRemove === null) {
            return;
        }

        unset($this->workerChatActions[$workerIdentifier]);

        $associatedChatIdentifier = $chatActionToRemove->chatId;
        if (!$this->chatHasPendingActions($associatedChatIdentifier)) {
            $this->stopChatActionTimer($associatedChatIdentifier);
        }
    }

    private function startChatActionTimer(int $targetChatIdentifier): void
    {
        $this->chatActionTimers[$targetChatIdentifier] = EventLoop::repeat(
            $this->actionIntervalSeconds,
            fn() => $this->sendActionToTargetChat($targetChatIdentifier)
        );

        $this->sendActionToTargetChat($targetChatIdentifier);
    }

    private function stopChatActionTimer(int $targetChatIdentifier): void
    {
        if (!isset($this->chatActionTimers[$targetChatIdentifier])) {
            return;
        }

        EventLoop::cancel($this->chatActionTimers[$targetChatIdentifier]);
        unset($this->chatActionTimers[$targetChatIdentifier]);
    }

    private function chatHasPendingActions(int $targetChatIdentifier): bool
    {
        foreach ($this->workerChatActions as $activeChatAction) {
            if ($activeChatAction->chatId === $targetChatIdentifier) {
                return true;
            }
        }

        return false;
    }

    private function sendActionToTargetChat(int $targetChatIdentifier): void
    {
        $responsibleWorkerIdentifier = null;
        $chatActionToSend = null;

        foreach ($this->workerChatActions as $currentWorkerIdentifier => $currentChatAction) {
            if ($currentChatAction->chatId === $targetChatIdentifier) {
                if ($this->finalMessageTracker->isFinalMessageBeingSentByWorker($currentWorkerIdentifier)) {
                    continue;
                }

                $responsibleWorkerIdentifier = $currentWorkerIdentifier;
                $chatActionToSend = $currentChatAction;
                break;
            }
        }

        if ($chatActionToSend === null) {
            $this->stopChatActionTimer($targetChatIdentifier);
            return;
        }

        $this->logger?->log(LogLevel::DEBUG, "Sending chat action to $targetChatIdentifier ($responsibleWorkerIdentifier): " . $chatActionToSend->action->name);

        $telegramApiRequestResult = Request::sendChatAction([
                                                                'chat_id' => $targetChatIdentifier,
                                                                'action'  => $chatActionToSend->action->name,
                                                            ]);

        if (!$telegramApiRequestResult->isOk()) {
            $this->logger?->log(LogLevel::ERROR, "Failed to send chat action to $targetChatIdentifier ($responsibleWorkerIdentifier): " . $telegramApiRequestResult->getDescription());
        }
    }
}
