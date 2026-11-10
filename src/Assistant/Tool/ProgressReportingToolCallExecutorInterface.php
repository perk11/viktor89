<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool;

use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;

/**
 * A tool that reports what it is doing while it runs (shown by /status). Each
 * call gets its own callback because the calls of one turn run concurrently.
 */
interface ProgressReportingToolCallExecutorInterface
{
    public function executeToolCall(array $arguments, MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): array;
}
