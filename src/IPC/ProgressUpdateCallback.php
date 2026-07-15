<?php

namespace Perk11\Viktor89\IPC;

use Perk11\Viktor89\Util\Telegram\ChatAction;

interface ProgressUpdateCallback
{
    public function __invoke(string $processor, string $status, ?ChatAction $chatAction = null): void;
    public function subscribe(callable $subscriber): void;

    /**
     * Tell the main process to stop sending typing notifications / drafts for
     * this worker's chat, and block until it confirms, so that no typing or
     * draft can appear after the actual message that is about to be sent.
     *
     * Implemented by the worker IPC callback (which owns the channel);
     * no-op for callbacks used outside the worker IPC flow.
     */
    public function notifyMessageAboutToBeSent(int $chatId): void;

}
