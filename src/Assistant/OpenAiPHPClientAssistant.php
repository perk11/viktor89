<?php

namespace Perk11\Viktor89\Assistant;

use OpenAI;
use OpenAI\Client;
use Perk11\Viktor89\Assistant\Tool\MessageChainAwareToolCallExecutorInterface;
use Perk11\Viktor89\Assistant\Tool\ToolCallExecutorInterface;
use Perk11\Viktor89\Assistant\Tool\ToolDefinition;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\ProcessingResultExecutor;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;

/** Uses https://github.com/openai-php/client */
class OpenAiPHPClientAssistant extends AbstractOpenAIAPiAssistant
{
    private readonly Client $openAiClient;

    /**
     * @param ToolDefinition[] $toolDefintions
     */
    public function __construct(
        private readonly ?string $model,
        UserPreferenceReaderInterface $systemPromptProcessor,
        UserPreferenceReaderInterface $responseStartProcessor,
        TelegramFileDownloader $telegramFileDownloader,
        AltTextProvider $altTextProvider,
        private readonly ProcessingResultExecutor $processingResultExecutor,
        int $telegramBotUserId,
        private readonly string $url,
        string $apiKey = '',
        bool $supportsImages,
        private readonly array $toolDefintions = [],
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
            $altTextProvider,
            $telegramBotUserId,
            $this->url
        );
    }

    public function getCompletionBasedOnContext(AssistantContext $assistantContext, ?callable $streamFunction = null, ?MessageChain $messageChain = null): string
    {
        $requestOptions = [
            'messages' => $assistantContext->toOpenAiMessagesArray(),
        ];
        if ($this->model !== null) {
            $requestOptions['model'] = $this->model;
        }
        foreach ($this->toolDefintions as $toolDefinition) {
            $requestOptions['tools'][] = $toolDefinition->toArray();
        }
        echo "Sending OpenAI request to " . $this->url ."...\n";
        echo json_encode($requestOptions, JSON_UNESCAPED_UNICODE) . PHP_EOL ;
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo 'Failed to convert context to JSON: ' . json_last_error_msg();
        }
        while (true) {
            echo "Calling OpenAI chat API (PHPClient)...\n";
            $result = $this->openAiClient->chat()->create($requestOptions);
            $choice0Message = $result->choices[0]->message ?? null;
            if ($choice0Message === null) {
                throw new \Exception("Unexpected response from OpenAI: " . json_encode($result, JSON_THROW_ON_ERROR));
            }
            $toolCalls = $choice0Message->toolCalls ?? [];

            if (count($toolCalls) === 0) {
                $completion = $choice0Message->content;
                if ($streamFunction !== null) {
                    //TODO: implement proper streaming
                    $streamFunction($completion);
                }

                return $completion;
            }
            echo "Received tool calls: " . json_encode($toolCalls, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
            if ($choice0Message->content !== '') {
                echo "Received non-empty content alongside tool call: " . $choice0Message->content . "\n";
                if ($messageChain !== null) {
                    $this->processingResultExecutor->execute(new ProcessingResult(InternalMessage::asResponseTo($messageChain->last(), $choice0Message->content), false));
                } else {
                    echo "Can't process non-empty content without message chain\n";
                }
            }
            $requestOptions['messages'][] = [
                'role' => 'assistant',
                'content' => $choice0Message->content,
                'tool_calls' => array_map(
                    static fn ($toolCall) => [
                        'id' => $toolCall->id,
                        'type' => 'function',
                        'function' => [
                            'name' => $toolCall->function->name,
                            'arguments' => $toolCall->function->arguments,
                        ],
                    ],
                    $toolCalls
                ),
            ];
            foreach ($toolCalls as $toolCall) {
                $functionName = $toolCall->function->name;
                if (!array_key_exists($functionName, $this->toolDefintions)) {
                    echo "Unknown tool called: $functionName\n";
                    $toolResult = ['content' => 'Error: Unknown tool call: ' . $functionName];
                } else {
                    $functionArgs = json_decode($toolCall->function->arguments, true, flags: JSON_THROW_ON_ERROR);
                    echo "Executing tool $functionName with args " . json_encode($functionArgs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
                    $toolCallClass = $this->toolDefintions[$functionName]->toolCallClass;
                    if ($toolCallClass instanceof  ToolCallExecutorInterface) {
                        $toolResult = $this->toolDefintions[$functionName]->toolCallClass->executeToolCall(
                            $functionArgs
                        );
                    } elseif ($toolCallClass instanceof MessageChainAwareToolCallExecutorInterface) {
                        $toolResult = $this->toolDefintions[$functionName]->toolCallClass->executeToolCall(
                            $functionArgs,
                            $messageChain
                        );
                    } else {
                        throw new \RuntimeException('Tool call class does not implement a supported interface: ' . get_class($toolCallClass));
                    }
                }
                $requestOptions['messages'][] = [
                    'role'         => 'tool',
                    'tool_call_id' => $toolCall->id,
                    'content'      => json_encode($toolResult, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ];
            }
        }
    }
}
