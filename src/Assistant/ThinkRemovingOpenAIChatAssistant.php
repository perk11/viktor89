<?php

namespace Perk11\Viktor89\Assistant;

class ThinkRemovingOpenAIChatAssistant extends OpenAiChatAssistant
{
    public function getCompletionBasedOnContext(AssistantContext $assistantContext, ?callable $streamFunction = null): string
    {
        $completion = parent::getCompletionBasedOnContext($assistantContext, $streamFunction);

        return preg_replace('/<think>.*?<\/think>/s', '', $completion);
    }

}
