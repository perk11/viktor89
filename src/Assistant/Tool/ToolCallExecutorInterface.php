<?php

namespace Perk11\Viktor89\Assistant\Tool;

use Perk11\Viktor89\MessageChain;

interface ToolCallExecutorInterface
{
    public function executeToolCall(array $arguments): array;
}
