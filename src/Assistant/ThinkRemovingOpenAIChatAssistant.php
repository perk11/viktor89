<?php

namespace Perk11\Viktor89\Assistant;

class ThinkRemovingOpenAIChatAssistant extends OpenAiChatAssistant
{
    public function getCompletionBasedOnContext(AssistantContext $assistantContext): string
    {
        $completion = parent::getCompletionBasedOnContext($assistantContext);

        return preg_replace('/<think>.*?<\/think>/s', '', $completion);
    }

}
