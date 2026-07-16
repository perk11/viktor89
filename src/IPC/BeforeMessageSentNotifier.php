<?php

namespace Perk11\Viktor89\IPC;

/**
 * Notifies the main process that a worker's final message is about to be sent,
 * blocking until it confirms that typing notifications and drafts for the chat
 * have been stopped — so that none can appear after the actual message.
 *
 * Kept separate from ProgressUpdateCallback (which is only for reporting
 * processing status). The executor calls this right before it sends/edits a
 * response.
 */
interface BeforeMessageSentNotifier
{
    public function notify(int $chatId): void;
}
