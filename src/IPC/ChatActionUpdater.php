<?php

namespace Perk11\Viktor89\IPC;

use Longman\TelegramBot\Request;
use Perk11\Viktor89\Util\Telegram\ChatAction;
use Revolt\EventLoop;

class ChatActionUpdater
{
    /** @var array<int, string> */
    private array $timers = [];

    /** @var array<int, ChatAction> */
    private array $actions = [];

    /** @var array<int, true> */
    private array $queuedChatIds = [];

    private ?int $activeChatId = null;

    public function updateAction(int $workerId, ?ChatAction $chatAction): void
    {
        if ($chatAction === null) {
            $this->removeAction($workerId);
            return;
        }

        $previousAction = $this->actions[$workerId] ?? null;
        if ($previousAction !== null && $previousAction->chatId !== $chatAction->chatId) {
            $this->removeAction($workerId);
        }

        $chatId = $chatAction->chatId;
        $this->actions[$workerId] = $chatAction;

        if ($this->activeChatId === null) {
            $this->startChatTimer($chatId);
            return;
        }

        if ($this->activeChatId === $chatId) {
            return;
        }

        $this->queuedChatIds[$chatId] = true;
    }

    public function removeAction(int $workerId): void
    {
        $chatAction = $this->actions[$workerId] ?? null;
        if ($chatAction === null) {
            return;
        }

        unset($this->actions[$workerId]);

        $chatId = $chatAction->chatId;
        if ($this->chatHasActions($chatId)) {
            return;
        }

        unset($this->queuedChatIds[$chatId]);

        if ($this->activeChatId === $chatId) {
            $this->stopChatTimer($chatId);
            $this->activeChatId = null;
            $this->startNextQueuedChat();
        }
    }

    private function startChatTimer(int $chatId): void
    {
        if (!$this->chatHasActions($chatId)) {
            return;
        }

        unset($this->queuedChatIds[$chatId]);

        $this->activeChatId = $chatId;
        $this->timers[$chatId] = EventLoop::repeat(4, fn() => $this->sendAction($chatId));

        $this->sendAction($chatId);
    }

    private function stopChatTimer(int $chatId): void
    {
        if (!isset($this->timers[$chatId])) {
            return;
        }

        EventLoop::cancel($this->timers[$chatId]);
        unset($this->timers[$chatId]);
    }

    private function startNextQueuedChat(): void
    {
        foreach (array_keys($this->queuedChatIds) as $queuedChatId) {
            unset($this->queuedChatIds[$queuedChatId]);

            if ($this->chatHasActions($queuedChatId)) {
                $this->startChatTimer($queuedChatId);
                return;
            }
        }
    }

    private function chatHasActions(int $chatId): bool
    {
        foreach ($this->actions as $action) {
            if ($action->chatId === $chatId) {
                return true;
            }
        }

        return false;
    }

    private function sendAction(int $chatId): void
    {
        $workerId = null;
        $action = null;

        foreach ($this->actions as $currentWorkerId => $currentAction) {
            if ($currentAction->chatId === $chatId) {
                $workerId = $currentWorkerId;
                $action = $currentAction;
                break;
            }
        }

        if ($action === null) {
            $this->stopChatTimer($chatId);

            if ($this->activeChatId === $chatId) {
                $this->activeChatId = null;
                $this->startNextQueuedChat();
            }

            return;
        }

        echo date('Y-m-d H:i:s') . " Sending chat action to $chatId ($workerId): " . $action->action->name . "\n";

        $result = Request::sendChatAction([
                                              'chat_id' => $chatId,
                                              'action' => $action->action->name,
                                          ]);

        if (!$result->isOk()) {
            echo date('Y-m-d H:i:s') . " Failed to send chat action to $chatId ($workerId): " . $result->getDescription() . "\n";
        }
    }
}
