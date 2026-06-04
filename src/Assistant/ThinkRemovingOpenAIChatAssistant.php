<?php

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;

class ThinkRemovingOpenAIChatAssistant extends OpenAiChatAssistant
{
    public function getCompletionBasedOnContext(AssistantContext $assistantContext, ?callable $streamFunction = null, ?MessageChain $messageChain = null, ?ProgressUpdateCallback $progressUpdateCallback = null): CompletionResponse
    {
        $completionResponse = parent::getCompletionBasedOnContext($assistantContext, $streamFunction, $messageChain, $progressUpdateCallback);

        return new CompletionResponse(
            preg_replace('/<think>.*?<\/think>/s', '', $completionResponse->content),
            $completionResponse->toolCalls
        );
    }

}
