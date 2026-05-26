<?php

namespace Perk11\Viktor89\Assistant\Tool;

use Perk11\Viktor89\MessageChain;

class UnsupportedMessageAwareToolCallExecutor implements MessageChainAwareToolCallExecutorInterface
{

    public function executeToolCall(array $arguments, MessageChain $messageChain): array
    {
        throw new \LogicException("Unsupported tool call");
    }
}
