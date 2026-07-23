<?php

namespace Perk11\Viktor89\Assistant;

use Exception;
use Perk11\Viktor89\Assistant\Tool\ToolCall;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\ProcessingResultExecutor;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class OpenAiChatAssistant extends AbstractOpenAIAPiAssistant
{
    public function __construct(
        private readonly ?string $model,
        UserPreferenceReaderInterface $systemPromptProcessor,
        UserPreferenceReaderInterface $responseStartProcessor,
        UserPreferenceReaderInterface $editFrequencyProcessor,
        TelegramFileDownloader $telegramFileDownloader,
        AltTextProvider $altTextProvider,
        ProcessingResultExecutor $processingResultExecutor,
        int $telegramBotId,
        string $url,
        string $apiKey = '',
        bool $supportsImages,
        array $toolDefintions = [],
        ?LoggerInterface $logger = null,
    )
    {
        if (count($toolDefintions) > 0) {
            throw new \RuntimeException('Tools are not supported by OpenAiChatAssistant');
        }
        parent::__construct(
            $systemPromptProcessor,
            $responseStartProcessor,
            $editFrequencyProcessor,
            $telegramFileDownloader,
            $altTextProvider,
            $telegramBotId,
            $url,
            $apiKey,
            $supportsImages,
            $logger
        );
    }
    public function getCompletionBasedOnContext(AssistantContext $assistantContext, ?callable $streamFunction = null, ?MessageChain $messageChain = null, ?ProgressUpdateCallback $progressUpdateCallback = null): CompletionResponse
    {
        $parameters = $this->getResponseParameters($assistantContext);
        $this->logger?->log(LogLevel::INFO, 'Calling OpenAI chat API...');
        $this->logger?->log(LogLevel::DEBUG, mb_substr(json_encode($parameters, JSON_UNESCAPED_UNICODE), 0, 4096));
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger?->log(LogLevel::ERROR, 'Failed to convert context to JSON: ' . json_last_error_msg());
        }

        $response = $this->openAi->chat($parameters);

        $this->logger?->log(LogLevel::DEBUG, (string) $response);
        $parsedResult = json_decode($response, JSON_THROW_ON_ERROR);
        if (!is_array($parsedResult) || !array_key_exists('choices', $parsedResult)) {
            throw new Exception("Unexpected response from OpenAI: $response");
        }
        $content = $parsedResult['choices'][0]['message']['content'] ?? '';
        $reasoning = $parsedResult['choices'][0]['message']['reasoning_content'] ?? null;
        $toolCalls = $parsedResult['choices'][0]['message']['tool_calls'] ?? null;
        if ($toolCalls !== null) {
            return new CompletionResponse(
                $content,
                array_map(
                    static fn(array $toolCall): ToolCall => new ToolCall(
                        $toolCall['id'],
                        $toolCall['function']['name'],
                        $toolCall['function']['arguments'],
                    ),
                    $toolCalls
                ),
                reasoning: $reasoning,
            );
        }

        if (is_string($content)) {
            return new CompletionResponse($content, reasoning: $reasoning);
        }
        if (is_array($content)) {
            $firstContentElement = current($content);
            if ($firstContentElement['type'] !== 'text') {
                throw new \Exception("Unexpected OpenAI response type: $firstContentElement[type]");
            }
            if (!array_key_exists('text', $firstContentElement)) {
                throw new \Exception("Missing \"text\" property in OpenAI response: $firstContentElement[type]");
            }

            return new CompletionResponse($firstContentElement['text'], reasoning: $reasoning);
        }

        throw new Exception("Unexpected OpenAI response format: " . json_encode($content));
    }

    protected function getResponseParameters(AssistantContext $assistantContext): array
    {
        $parameters = [
            'messages' => $assistantContext->toOpenAiMessagesArray(false),
        ];
        if ($this->model !== null) {
            $parameters['model'] = $this->model;
        }
        return $parameters;
    }
}
