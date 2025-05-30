<?php

namespace Perk11\Viktor89;

class BlockedChatProcessor implements MessageChainProcessor
{
    public function __construct(private readonly array $blockedChatIds)
    {
    }

    public function processMessageChain(MessageChain $messageChain): ProcessingResult
    {
        $block = in_array($messageChain->last()->chatId, $this->blockedChatIds, false);
        return new ProcessingResult(null, $block);

    }
}
