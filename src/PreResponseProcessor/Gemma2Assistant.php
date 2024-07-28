<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use JsonException;
use Orhanerday\OpenAi\OpenAi;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\OpenAiCompletionStringParser;
use Perk11\Viktor89\TelegramChainBasedResponderInterface;

class Gemma2Assistant implements TelegramChainBasedResponderInterface
{
    private OpenAi $openAi;

    private array $tokens = [
        '<end_of_turn>',
        '<start_of_turn>',
        '<bos>',
        '<eos>',
        '<pad>',
        '<unk>',
    ];

    private readonly array $tokenReplacements;

    public function __construct(
        private readonly UserPreferenceSetByCommandProcessor $systemPromptProcessor,
        private readonly UserPreferenceSetByCommandProcessor $responseStartProcessor,
        private readonly OpenAiCompletionStringParser $openAiCompletionStringParser,
    ) {
        $this->openAi = new OpenAi('');
        $this->openAi->setBaseURL($_ENV['OPENAI_ASSISTANT_SERVER']);
        $this->tokenReplacements = array_fill(0, count($this->tokens) - 1, '');
    }

    private function getCompletion(string $prompt): string
    {
        $opts = [
            'prompt' => $prompt,
            'stream' => true,
            "stop"   => [
                "<end_of_turn>",
                "<eos>",
            ],
        ];
        $fullContent = '';
        try {
            $jsonPart = null;
            $this->openAi->completion($opts, function ($curl_info, $data) use (&$fullContent, &$jsonPart) {
                if ($jsonPart === null) {
                    $dataToParse = $data;
                } else {
                    $dataToParse = $jsonPart . $data;
                }
                try {
                    $parsedData = $this->openAiCompletionStringParser->parse($dataToParse);
                    $jsonPart = null;
                } catch (JSONException $e) {
                    echo "\nIncomplete JSON received, postponing parsing until more is received\n";
                    $jsonPart = $dataToParse;
                    return strlen($data);
                }
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
            echo $e->getTraceAsString();

            return 'Ошибка подключения к llama.cpp';
        }

        return trim($fullContent);
    }

    public function getResponseByMessageChain(array $messageChain): InternalMessage
    {
        $userId = $messageChain[count($messageChain) - 1]->userId;
        $systemPrompt = $this->systemPromptProcessor->getCurrentPreferenceValue($userId) ??
            "Always respond in Russian.\n";

        $prompt = '';
        $firstUserMessage = true;
        $human = count($messageChain) % 2 === 1;
        foreach ($messageChain as $message) {
            $previousMessageUserName = $human ? 'user' : 'model';
            if ($firstUserMessage && $previousMessageUserName === 'user') {
                $prompt = "<start_of_turn>user\n$systemPrompt\n";
                $firstUserMessage = false;
            } else {
                $prompt .= "<start_of_turn>$previousMessageUserName\n";
            }
            $escapedMessageText = str_replace($this->tokens, $this->tokenReplacements, $message->messageText);
            $prompt .= "$escapedMessageText<end_of_turn>";
            $human = !$human;
        }
        $prompt .= "<start_of_turn>model\n";
        $responseStart = $this->responseStartProcessor->getCurrentPreferenceValue($userId);
        if ($responseStart !== null) {
            $prompt .= $responseStart;
        }
        echo $prompt;

        $lastMessage = $messageChain[count($messageChain) - 1];
        $message = new InternalMessage();
        $message->replyToMessageId = $lastMessage->id;
        $message->chatId = $lastMessage->chatId;
        $message->parseMode = 'MarkdownV2';
        $message->messageText = $responseStart . trim($this->getCompletion($prompt));
        for ($i = 0; $i < 5; $i++) {
            if (trim($message->messageText) === '') {
                echo "Bad response detected, trying again\n";
                $message->messageText = $responseStart . $this->getCompletion($prompt);
            } else {
                break;
            }
        }

        return $message;
    }
}
