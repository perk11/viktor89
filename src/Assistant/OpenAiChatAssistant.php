<?php

namespace Perk11\Viktor89\Assistant;

use Exception;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;

class OpenAiChatAssistant extends AbstractOpenAIAPiAssistant
{
    public function __construct(
        private readonly ?string $model,
        UserPreferenceReaderInterface $systemPromptProcessor,
        UserPreferenceReaderInterface $responseStartProcessor,
        TelegramFileDownloader $telegramFileDownloader,
        int $telegramBotId,
        string $url,
        string $apiKey = '',
    )
    {
        parent::__construct($systemPromptProcessor, $responseStartProcessor, $telegramFileDownloader,$telegramBotId, $url, $apiKey);
    }
    public function getCompletionBasedOnContext(AssistantContext $assistantContext, ?callable $streamFunction = null): string
    {
        $parameters = $this->getResponseParameters($assistantContext);
        echo "Calling OpenAI chat API...\n";
        echo mb_substr(json_encode($parameters, JSON_UNESCAPED_UNICODE) , 0, 4096). PHP_EOL ;
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo 'Failed to convert context to JSON: ' . json_last_error_msg();
        }

        $response = $this->openAi->chat($parameters);

        echo $response;
        $parsedResult = json_decode($response, JSON_THROW_ON_ERROR);
        if (!is_array($parsedResult) || !array_key_exists('choices', $parsedResult)) {
            throw new Exception("Unexpected response from OpenAI: $response");
        }

        return $parsedResult['choices'][0]['message']['content'];
    }

    protected function getResponseParameters(AssistantContext $assistantContext): array
    {
        $parameters = [
            'messages' => $assistantContext->toOpenAiMessagesArray(),
        ];
        if ($this->model !== null) {
            $parameters['model'] = $this->model;
        }
        return $parameters;
    }
}
