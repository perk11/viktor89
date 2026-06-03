<?php

namespace Perk11\Viktor89\Assistant\Tool;

use Perk11\Viktor89\MessageChain;

class UnsupportedToolCallExecutor implements ToolCallExecutorInterface
{

    public function executeToolCall(array $arguments): array
    {
        throw new \LogicException("Unsupported tool call");
    }
}
