<?php

namespace Perk11\Viktor89\Assistant\Tool;

use Perk11\Viktor89\MessageChain;

interface MessageChainAwareToolCallExecutorInterface
{
    public function executeToolCall(array $arguments, MessageChain $messageChain): array;
}
