<?php

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\MessageChain;

class ThinkRemovingOpenAIChatAssistant extends OpenAiChatAssistant
{
    public function getCompletionBasedOnContext(AssistantContext $assistantContext, ?callable $streamFunction = null, ?MessageChain $messageChain = null): CompletionResponse
    {
        $completionResponse = parent::getCompletionBasedOnContext($assistantContext, $streamFunction);

        return new CompletionResponse(
            preg_replace('/<think>.*?<\/think>/s', '', $completionResponse->content),
            $completionResponse->toolCalls
        );
    }

}
