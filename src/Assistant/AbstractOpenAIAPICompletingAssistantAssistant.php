<?php

namespace Perk11\Viktor89\Assistant;

use JsonException;
use Orhanerday\OpenAi\OpenAi;
use Perk11\Viktor89\AbortStreamingResponse\AbortableStreamingResponseGenerator;
use Perk11\Viktor89\AbortStreamingResponse\AbortStreamingResponseHandler;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\OpenAiCompletionStringParser;
use Perk11\Viktor89\PreResponseProcessor\UserPreferenceSetByCommandProcessor;
use Perk11\Viktor89\TelegramChainBasedResponderInterface;

abstract class AbstractOpenAIAPICompletingAssistantAssistant implements TelegramChainBasedResponderInterface,
                                                                        AbortableStreamingResponseGenerator,
                                                                        ContextCompletingAssistantInterface
{
    /** @var AbortStreamingResponseHandler[] */
    private array $abortResponseHandlers = [];

    public function __construct(
        protected readonly OpenAI $openAi,
        private readonly UserPreferenceSetByCommandProcessor $systemPromptProcessor,
        private readonly UserPreferenceSetByCommandProcessor $responseStartProcessor,
        private readonly OpenAiCompletionStringParser $openAiCompletionStringParser,
    )
    {
    }

    public function addAbortResponseHandler(AbortStreamingResponseHandler $abortResponseHandler): void
    {
        $this->abortResponseHandlers[] = $abortResponseHandler;
    }

    public function getCompletionBasedOnContext(AssistantContext $assistantContext): string
    {
        $prompt = $this->convertContextToPrompt($assistantContext);

        return $this->getCompletion($prompt);
    }

    /** @param InternalMessage[] $messageChain */
    protected function convertMessageChainToPrompt(
        array $messageChain,
        ?string $systemPrompt,
        ?string $responseStart
    ): string {
        $isUser = count($messageChain) % 2 === 1;
        $assistantContext = new AssistantContext();
        $assistantContext->systemPrompt = $systemPrompt;
        $assistantContext->responseStart = $responseStart;
        foreach ($messageChain as $message) {
            $assistantContextMessage = new AssistantContextMessage();
            $assistantContextMessage->isUser = $isUser;
            $assistantContextMessage->text = $message->messageText;
            $assistantContext->messages[] = $assistantContextMessage;
            $isUser = !$isUser;
        }

        return $this->convertContextToPrompt($assistantContext);
    }

    abstract protected function convertContextToPrompt(AssistantContext $assistantContext): string;

    public function getResponseByMessageChain(array $messageChain): InternalMessage
    {
        $userId = $messageChain[count($messageChain) - 1]->userId;
        $responseStart = $this->responseStartProcessor->getCurrentPreferenceValue($userId);
        $systemPrompt = $this->systemPromptProcessor->getCurrentPreferenceValue($userId) ??
            "Always respond in Russian.\n";

        $prompt = $this->convertMessageChainToPrompt($messageChain, $systemPrompt, $responseStart);
        echo $prompt;

        /** @var InternalMessage $lastMessage */
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
        $aborted = false;
        $fullContent = '';
        $jsonPart = null;
        try {
            $this->openAi->completion(
                $opts,
                function ($curl_info, $data) use (&$fullContent, &$jsonPart, &$aborted, $prompt) {
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
                    foreach ($this->abortResponseHandlers as $abortResponseHandler) {
                        $newResponse = $abortResponseHandler->getNewResponse($prompt, $fullContent);
                        if ($newResponse !== false) {
                            $fullContent = $newResponse;
                            $aborted = true;

                            return 0;
                        }
                }

                return strlen($data);
            });
        } catch (\Exception $e) {
            if ($aborted) {
                return trim($fullContent);
            }
            throw $e;
        }

        return rtrim($fullContent);
    }
}
