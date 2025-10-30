<?php

namespace Perk11\Viktor89;

use Perk11\Viktor89\IPC\ProgressUpdateCallback;

interface MessageChainProcessor
{
    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult;
}
