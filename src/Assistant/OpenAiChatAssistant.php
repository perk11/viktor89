<?php

namespace Perk11\Viktor89\Assistant;

class OpenAiChatAssistant extends AbstractOpenAIAPiAssistant
{
    public function getCompletionBasedOnContext(AssistantContext $assistantContext): string
    {
        echo "Calling OpenAI chat API...\n";
        echo $assistantContext . PHP_EOL;
        $response = $this->openAi->chat(
            $this->getResponseParameters($assistantContext)
        );

        echo $response;
        $parsedResult = json_decode($response, JSON_THROW_ON_ERROR);
        if (!is_array($parsedResult) || !array_key_exists('choices', $parsedResult)) {
            throw new \Exception("Unexpected response from OpenAI: $response");
        }

        return $parsedResult['choices'][0]['message']['content'];
    }

    protected function getResponseParameters(AssistantContext $assistantContext): array
    {
        return [
            'messages' => $assistantContext->toOpenAiArray(),
        ];
    }
}
