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

    public function updateAction(int $workerId, ?ChatAction $chatAction): void
    {
        if ($chatAction === null) {
            $this->removeAction($workerId);
            return;
        }

        $chatId = $chatAction->chatId;
        $this->actions[$workerId] = $chatAction;

        if (!isset($this->timers[$chatId])) {
            $this->timers[$chatId] = EventLoop::repeat(4, fn() => $this->sendAction($chatId));
            $this->sendAction($chatId);
        }
    }

    public function removeAction(int $workerId): void
    {
        $chatAction = $this->actions[$workerId] ?? null;
        if ($chatAction === null) {
            return;
        }

        unset($this->actions[$workerId]);

        $chatId = $chatAction->chatId;
        foreach ($this->actions as $action) {
            if ($action->chatId === $chatId) {
                return;
            }
        }

        if (isset($this->timers[$chatId])) {
            EventLoop::cancel($this->timers[$chatId]);
            unset($this->timers[$chatId]);
        }
    }

    private function sendAction(int $chatId): void
    {
        $action = null;
        foreach ($this->actions as $a) {
            if ($a->chatId === $chatId) {
                $action = $a;
                break;
            }
        }

        if ($action === null) {
            return;
        }

        echo date('Y-m-d H:i:s') . " Sending chat action to $chatId: " . $action->action->name . "\n";
        $result = Request::sendChatAction([
            'chat_id' => $chatId,
            'action'  => $action->action->name,
        ]);

        if (!$result->isOk()) {
            echo date('Y-m-d H:i:s') . " Failed to send chat action to $chatId: " . $result->getDescription() . "\n";
        }
    }
}
