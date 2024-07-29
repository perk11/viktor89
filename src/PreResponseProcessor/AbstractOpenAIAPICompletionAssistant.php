<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use JsonException;
use Orhanerday\OpenAi\OpenAi;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\OpenAiCompletionStringParser;
use Perk11\Viktor89\TelegramChainBasedResponderInterface;

abstract class AbstractOpenAIAPICompletionAssistant implements TelegramChainBasedResponderInterface
{

    public function __construct(
        protected readonly OpenAI $openAi,
        private readonly UserPreferenceSetByCommandProcessor $systemPromptProcessor,
        private readonly UserPreferenceSetByCommandProcessor $responseStartProcessor,
        private readonly OpenAiCompletionStringParser $openAiCompletionStringParser,
    )
    {
    }

    abstract protected function convertMessageChainToPrompt(array $messageChain, string $systemPrompt, ?string $responseStart): string;

    public function getResponseByMessageChain(array $messageChain): InternalMessage
    {
        $userId = $messageChain[count($messageChain) - 1]->userId;
        $responseStart = $this->responseStartProcessor->getCurrentPreferenceValue($userId);
        $systemPrompt = $this->systemPromptProcessor->getCurrentPreferenceValue($userId) ??
            "Always respond in Russian.\n";

        $prompt = $this->convertMessageChainToPrompt($messageChain, $systemPrompt, $responseStart);
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

    protected function getCompletionOptions(string $prompt): array
    {
        return [
            'prompt' => $prompt,
            'stream' => true,
        ];

    }
    protected function getCompletion(string $prompt): string
    {
        $opts = $this->getCompletionOptions($prompt);
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
        }

        return trim($fullContent);
    }
}
