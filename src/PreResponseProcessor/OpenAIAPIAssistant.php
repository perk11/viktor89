<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Orhanerday\OpenAi\OpenAi;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\TelegramChainBasedResponderInterface;

class OpenAIAPIAssistant implements TelegramChainBasedResponderInterface
{

    private OpenAi $openAi;

    public function __construct(
        private readonly UserPreferenceSetByCommandProcessor $systemPromptProcessor,
        private readonly UserPreferenceSetByCommandProcessor $responseStartProcessor,
    ) {
        $this->openAi = new OpenAi('');
        $this->openAi->setBaseURL($_ENV['OPENAI_ASSISTANT_SERVER']);
    }

    private function getCompletion(string $prompt, string $personality): string
    {
        $opts = [
            'prompt' => $prompt,
            'stream' => true,
            "stop"   => [
                "</s>",
                "$personality:",
                "User:",
            ],
        ];
        $fullContent = '';
        try {
            $this->openAi->completion($opts, function ($curl_info, $data) use (&$fullContent, $prompt) {
                $parsedData = parse_completion_string($data);
                echo $parsedData['content'];
                $fullContent .= $parsedData['content'];
                if (mb_strlen($fullContent) > 8192) {
                    echo "Max length reached, aborting response\n";

                    return 0;
                }

                return strlen($data);
            });
        } catch (\Exception $e) {
            echo "Got error when accessing OpenAI API: ";
            echo $e->getMessage();

            return 'Ошибка подключения к llama.cpp';
        }

        return trim($fullContent);
    }

    public function getResponseByMessageChain(array $messageChain): InternalMessage
    {
        $personality = 'Gemma';

        $userId = $messageChain[count($messageChain) - 1]->userId;
        $systemPrompt = $this->systemPromptProcessor->getCurrentPreferenceValue($userId) ??
            "This is a conversation between User and $personality, a friendly assistant chatbot. $personality is helpful, kind, honest, good at writing, knows everything, and never fails to answer any requests immediately and with precision.";
        $prompt = "$systemPrompt\n\n";

        $human = count($messageChain) % 2 === 1;
        foreach ($messageChain as $message) {
            $previousMessageUserName = $human ? 'User' : $personality;
            $prompt .= "$previousMessageUserName: " . $message->messageText . "\n";
            $human = !$human;
        }
        $prompt .= "$personality: ";
        $responseStart = $this->responseStartProcessor->getCurrentPreferenceValue($userId);
        if ($responseStart !== null) {
            $prompt .= $responseStart;
        }
        echo $prompt;

        $lastMessage = $messageChain[count($messageChain) - 1];
        $message = new InternalMessage();
        $message->replyToMessageId = $lastMessage->id;
        $message->chatId = $lastMessage->chatId;
//        $message->parseMode = 'MarkdownV2';
        $message->messageText = $responseStart . trim($this->getCompletion($prompt, $personality));

        return $message;
    }
}
