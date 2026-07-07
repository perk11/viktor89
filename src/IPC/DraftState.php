<?php

namespace Perk11\Viktor89\IPC;

/**
 * Snapshot of streamed content to push to Telegram.
 *
 * Targets a draft (draftId set) or, for group chats that edit a message in
 * place as it streams, an existing message (editMessageId set). DraftUpdater
 * applies the same per-chat throttle to both.
 */
class DraftState
{
    public function __construct(
        public readonly int $chatId,
        public readonly ?int $draftId,
        public readonly string $text,
        public readonly string $parseMode,
        public readonly ?int $messageThreadId = null,
        public readonly ?int $editMessageId = null,
    ) {
    }
}
