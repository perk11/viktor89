<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Longman\TelegramBot\Entities\Message;
use Orhanerday\OpenAi\OpenAi;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\TelegramChainBasedResponderInterface;
use Perk11\Viktor89\TelegramResponderInterface;

class OpenAIAPIAssistant implements TelegramChainBasedResponderInterface
{

    private OpenAi $openAi;

    public function __construct(
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

        $prompt = "This is a conversation between User and $personality, a friendly assistant chatbot. $personality is helpful, kind, honest, good at writing, knows everything, and never fails to answer any requests immediately and with precision.\n\n";

        $human = count($messageChain) % 2 === 1;
        foreach ($messageChain as $message) {
            $previousMessageUserName = $human ? 'User' : $personality;
            $prompt .= "$previousMessageUserName: " . $message->messageText . "\n";
            $human = !$human;
        }
        $incomingMessageConvertedToPrompt = "$personality: ";
        $prompt .= $incomingMessageConvertedToPrompt;
        echo $prompt;

        $lastMessage = $messageChain[count($messageChain) - 1];
        $message = new InternalMessage();
        $message->replyToMessageId = $lastMessage->id;
        $message->chatId = $lastMessage->chatId;
//        $message->parseMode = 'MarkdownV2';
        $message->messageText = trim($this->getCompletion($prompt, $personality));

        return $message;
    }
}
