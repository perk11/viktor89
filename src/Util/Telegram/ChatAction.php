<?php

namespace Perk11\Viktor89\Util\Telegram;

class ChatAction
{
    public function __construct(
        public int $chatId,
        public ChatActionEnum $action,
    ) {
    }
}
