<?php

namespace Perk11\Viktor89\Assistant;

class FinalMessageOpenAiChatAssistant extends OpenAiChatAssistant
{
    public function getCompletionBasedOnContext(AssistantContext $assistantContext, ?callable $streamFunction = null): string
    {
        $completion = parent::getCompletionBasedOnContext($assistantContext, $streamFunction);

        if (
            preg_match(
                '/<\|channel\|\>final<\|message\|\>(.*?)(?=<\|channel\|\>|$)/s',
                $completion,
                $matches
            )
        ) {
            return trim($matches[1]);
        }

        return $completion;

    }

}
