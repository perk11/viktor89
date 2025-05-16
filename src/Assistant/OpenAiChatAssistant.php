<?php

namespace Perk11\Viktor89\Assistant;

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
    )
    {
        parent::__construct($systemPromptProcessor, $responseStartProcessor, $telegramFileDownloader,$telegramBotId, $url);
    }
    public function getCompletionBasedOnContext(AssistantContext $assistantContext): string
    {
        $parameters = $this->getResponseParameters($assistantContext);
        echo "Calling OpenAI chat API...\n";
        echo json_encode($parameters, JSON_UNESCAPED_UNICODE) . PHP_EOL ;
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo 'Failed to convert context to JSON: ' . json_last_error_msg();
        }

        $response = $this->openAi->chat($parameters);

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
            'messages' => $this->assistantContextToOpenAiArray($assistantContext),
        ];
        if ($this->model !== null) {
            $parameters['model'] = $this->model;
        }
        return $parameters;
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
