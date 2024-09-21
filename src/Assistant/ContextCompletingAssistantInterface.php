<?php

namespace Perk11\Viktor89\Assistant;

interface ContextCompletingAssistantInterface
{
    public function getCompletionBasedOnContext(AssistantContext $assistantContext): string;
}
