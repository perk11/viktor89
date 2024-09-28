<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use JsonException;
use Orhanerday\OpenAi\OpenAi;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\OpenAiCompletionStringParser;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;

class OpenAIAPIAssistant implements MessageChainProcessor
{

    private OpenAi $openAi;

    public function __construct(
        private readonly UserPreferenceSetByCommandProcessor $systemPromptProcessor,
        private readonly UserPreferenceSetByCommandProcessor $responseStartProcessor,
        private readonly OpenAiCompletionStringParser $openAiCompletionStringParser,
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

    public function processMessageChain(MessageChain $messageChain): ProcessingResult
    {
        $personality = 'Gemma';

        $userId = $messageChain->last()->userId;
        $systemPrompt = $this->systemPromptProcessor->getCurrentPreferenceValue($userId) ??
            "This is a conversation between User and $personality, a friendly assistant chatbot. $personality is helpful, kind, honest, good at writing, knows everything, and never fails to answer any requests immediately and with precision. $personality does not reason about why she responded a certain way. $personality's responses are always in Russian.";
        $prompt = "$systemPrompt\n\n";

        $human = $messageChain->count() % 2 === 1;
        foreach ($messageChain->getMessages() as $message) {
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

        $lastMessage = $messageChain->last();
        $message = new InternalMessage();
        $message->replyToMessageId = $lastMessage->id;
        $message->chatId = $lastMessage->chatId;
        $message->parseMode = 'MarkdownV2';
        $message->messageText = $responseStart . trim($this->getCompletion($prompt, $personality));
        for ($i = 0; $i < 5; $i++) {
            if (trim($message->messageText) === '') {
                echo "Bad response detected, trying again\n";
                $message->messageText = $responseStart . $this->getCompletion($prompt, $personality);
            } else {
                break;
            }
        }

        return new ProcessingResult($message, true);
    }
}
