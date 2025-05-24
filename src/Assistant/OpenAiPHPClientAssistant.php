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
            'messages' => $this->assistantContextToOpenAiArray($assistantContext),
        ];
        if ($this->model !== null) {
            $requestOptions['model'] = $this->model;
        }
        echo "Sending OpenAI request to " . $this->url ."...";
        $result = $this->openAiClient->chat()->create($requestOptions);

        echo $result->choices[0]->message->content;

        return $result->choices[0]->message->content;
    }

    private function assistantContextToOpenAiArray(AssistantContext $assistantContext): array
    {
        if ($assistantContext->responseStart !== null) {
            throw new \Exception('responseStart specified, but it can not be converted to OpenAi array');
        }
        $openAiMessages = [];
        if ($assistantContext->systemPrompt !== null) {
            $openAiMessages[] = [
                'role'    => 'system',
                'content' => $assistantContext->systemPrompt,
            ];
        }

        foreach ($assistantContext->messages as $message) {
            if ($message->photo === null) {
                $content = $message->text;
            } else {
                $content = [
                    [
                        'type' => 'text',
                        'text' => $message->text,
                    ],
                    [
                        'type'      => 'image_url',
                        'image_url' => [
                            'url' => 'data:image/jpeg;base64,' . base64_encode($message->photo),
                        ],
                    ],

                ];
            }

            $openAiMessages[] = [
                'role'    => $message->isUser ? 'user' : 'assistant',
                'content' => $content,
            ];
        }

        return $openAiMessages;
    }
}
