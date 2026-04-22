<?php

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\MessageChain;

interface ContextCompletingAssistantInterface
{
    public function getCompletionBasedOnContext(AssistantContext $assistantContext, ?callable $streamFunction = null, ?MessageChain $messageChain = null): string;
}
