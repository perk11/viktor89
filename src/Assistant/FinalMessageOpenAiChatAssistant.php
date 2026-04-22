<?php

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\MessageChain;

class FinalMessageOpenAiChatAssistant extends OpenAiChatAssistant
{
    public function getCompletionBasedOnContext(AssistantContext $assistantContext, ?callable $streamFunction = null, ?MessageChain $messageChain = null): string
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
