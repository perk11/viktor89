<?php

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\UserPreferenceReaderInterface;

class OpenAiChatAssistant extends AbstractOpenAIAPiAssistant
{
    public function __construct(
        private readonly ?string $model,
        UserPreferenceReaderInterface $systemPromptProcessor,
        UserPreferenceReaderInterface $responseStartProcessor,
        string $url,
    )
    {
        parent::__construct($systemPromptProcessor, $responseStartProcessor, $url);
    }
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
        $parameters = [
            'messages' => $assistantContext->toOpenAiArray(),
        ];
        if ($this->model !== null) {
            $parameters['model'] = $this->model;
        }
        return $parameters;
    }
}
