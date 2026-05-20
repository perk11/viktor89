<?php

namespace Perk11\Viktor89\IPC;

use DateTimeImmutable;

class RunningTask
{
    public function __construct(
        public readonly string $processor,
        public readonly string $message,
        public readonly DateTimeImmutable $startTime,
        public readonly ?\Perk11\Viktor89\Util\Telegram\ChatAction $chatAction = null,
        public readonly ?DateTimeImmutable $actionAddedTime = null,
    ) {
    }
}
