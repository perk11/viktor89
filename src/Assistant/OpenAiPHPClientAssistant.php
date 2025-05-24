<?php

namespace Perk11\Viktor89\Assistant;

use OpenAI;
use OpenAI\Client;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;

/** Uses https://github.com/openai-php/client */
class OpenAiPHPClientAssistant extends AbstractOpenAIAPiAssistant
{
    private readonly Client $openAiClient;

    public function __construct(
        private readonly ?string $model,
        UserPreferenceReaderInterface $systemPromptProcessor,
        UserPreferenceReaderInterface $responseStartProcessor,
        TelegramFileDownloader $telegramFileDownloader,
        int $telegramBotUserId,
        private readonly string $url,
        string $apiKey = '',
    ) {
        $openAiFactory = OpenAI::factory()
            ->withBaseUri(rtrim($url, '/'));
        if ($apiKey !== '') {
            $openAiFactory->withApiKey($apiKey);
        }
        $this->openAiClient = $openAiFactory->make();
        parent::__construct(
            $systemPromptProcessor,
            $responseStartProcessor,
            $telegramFileDownloader,
            $telegramBotUserId,
            $this->url
        );
    }

    public function getCompletionBasedOnContext(AssistantContext $assistantContext): string
    {
        $requestOptions = [
            'messages' => $assistantContext->toOpenAiMessagesArray(),
        ];
        if ($this->model !== null) {
            $requestOptions['model'] = $this->model;
        }
        echo "Sending OpenAI request to " . $this->url ."...\n";
        echo json_encode($requestOptions, JSON_UNESCAPED_UNICODE) . PHP_EOL ;
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo 'Failed to convert context to JSON: ' . json_last_error_msg();
        }
        $result = $this->openAiClient->chat()->create($requestOptions);

        echo $result->choices[0]->message->content;

        return $result->choices[0]->message->content;
    }
}
