<?php

namespace Perk11\Viktor89\Assistant\Tool;

interface ToolCallExecutorInterface
{
    public function executeToolCall(array $arguments): array;
}
