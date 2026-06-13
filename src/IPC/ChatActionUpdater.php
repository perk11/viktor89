<?php

namespace Perk11\Viktor89\IPC;

use Longman\TelegramBot\Request;
use Perk11\Viktor89\Util\Telegram\ChatAction;
use Revolt\EventLoop;

class ChatActionUpdater
{
    /** @var array<int, string> */
    private array $chatActionTimers = [];

    /** @var array<int, ChatAction> */
    private array $workerChatActions = [];

    public function updateAction(int $workerIdentifier, ?ChatAction $chatActionToUpdate): void
    {
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
            4,
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
                $responsibleWorkerIdentifier = $currentWorkerIdentifier;
                $chatActionToSend = $currentChatAction;
                break;
            }
        }

        if ($chatActionToSend === null) {
            $this->stopChatActionTimer($targetChatIdentifier);
            return;
        }

        echo date('Y-m-d H:i:s') . " Sending chat action to $targetChatIdentifier ($responsibleWorkerIdentifier): " . $chatActionToSend->action->name . "\n";

        $telegramApiRequestResult = Request::sendChatAction([
                                                                'chat_id' => $targetChatIdentifier,
                                                                'action'  => $chatActionToSend->action->name,
                                                            ]);

        if (!$telegramApiRequestResult->isOk()) {
            echo date('Y-m-d H:i:s') . " Failed to send chat action to $targetChatIdentifier ($responsibleWorkerIdentifier): " . $telegramApiRequestResult->getDescription() . "\n";
        }
    }
}
