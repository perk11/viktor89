<?php

namespace Perk11\Viktor89\IPC;

class DraftState
{
    public function __construct(
        public readonly int $chatId,
        public readonly int $draftId,
        public readonly string $text,
        public readonly string $parseMode,
        public readonly ?int $messageThreadId = null,
    ) {
    }
}
