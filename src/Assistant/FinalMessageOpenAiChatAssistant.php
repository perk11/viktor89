<?php

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\MessageChain;

class FinalMessageOpenAiChatAssistant extends OpenAiChatAssistant
{
    public function getCompletionBasedOnContext(AssistantContext $assistantContext, ?callable $streamFunction = null, ?MessageChain $messageChain = null): CompletionResponse
    {
        $completionResponse = parent::getCompletionBasedOnContext($assistantContext, $streamFunction);
        $completion = $completionResponse->content;

        if (
            preg_match(
                '/<\|channel\|\>final<\|message\|\>(.*?)(?=<\|channel\|\>|$)/s',
                $completion,
                $matches
            )
        ) {
            return new CompletionResponse(trim($matches[1]), $completionResponse->toolCalls);
        }

        return $completionResponse;

    }

}
