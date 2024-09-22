<?php

namespace Perk11\Viktor89\Assistant;

class OpenAiChatAssistant extends AbstractOpenAIAPiAssistant
{
    public function getCompletionBasedOnContext(AssistantContext $assistantContext): string
    {
        echo "Calling OpenAI chat API...\n";
        echo $assistantContext . PHP_EOL;
        $messages = $assistantContext->toOpenAiArray();
        $response = $this->openAi->chat(
            [
                'messages' => $messages,
            ]
        );

        echo $response;
        $parsedResult = json_decode($response, JSON_THROW_ON_ERROR);
        if (!array_key_exists('choices', $parsedResult)) {
            echo "Unexpected response from OpenAI: $response \n";
        }

        return $parsedResult['choices'][0]['message']['content'];
    }
}
