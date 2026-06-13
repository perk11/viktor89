<?php

namespace Perk11\Viktor89\Assistant;

use GuzzleHttp\Client as GuzzleClient;
use OpenAI;
use OpenAI\Client;
use Perk11\Viktor89\Assistant\Tool\MessageChainAwareToolCallExecutorInterface;
use Perk11\Viktor89\Assistant\Tool\ToolCallExecutorInterface;
use Perk11\Viktor89\Assistant\Tool\ToolDefinition;
use Perk11\Viktor89\Assistant\Tool\ToolCall;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\ProcessingResultExecutor;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;

/** Uses https://github.com/openai-php/client */
class OpenAiPHPClientAssistant extends AbstractOpenAIAPiAssistant
{
    private const int REPETITION_THRESHOLD_CHARACTERS = 512;
    private readonly Client $openAiClient;

    /**
     * @param ToolDefinition[] $toolDefintions
     */
    public function __construct(
        private readonly ?string $model,
        UserPreferenceReaderInterface $systemPromptProcessor,
        UserPreferenceReaderInterface $responseStartProcessor,
        UserPreferenceReaderInterface $editFrequencyProcessor,
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
            ->withBaseUri(rtrim($url, '/'))
            ->withHttpClient(new GuzzleClient(['timeout' => 3600*4]));
        if ($apiKey !== '') {
            $openAiFactory->withApiKey($apiKey);
        }
        $this->openAiClient = $openAiFactory->make();
        parent::__construct(
            $systemPromptProcessor,
            $responseStartProcessor,
            $editFrequencyProcessor,
            $telegramFileDownloader,
            $altTextProvider,
            $telegramBotUserId,
            $this->url,
            $apiKey,
            $supportsImages,
        );
    }

    public function getCompletionBasedOnContext(
        AssistantContext $assistantContext,
        ?callable $streamFunction = null,
        ?MessageChain $messageChain = null,
        ?ProgressUpdateCallback $progressUpdateCallback = null
    ): CompletionResponse {
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

        $allToolCalls = [];
        $accumulatedContent = '';
        while (true) {
            $statusMessage = "Calling OpenAI chat API (PHPClient)...";
            if (count($allToolCalls) > 0) {
                $statusMessage .= " (" . count($allToolCalls) . " tool calls)";
            }
            if ($progressUpdateCallback !== null) {
                $progressUpdateCallback(static::class, $statusMessage);
            } else {
                echo $statusMessage . "\n";
            }
            $content = '';
            /** @var array<int, object> $toolCallsByIndex */
            $toolCallsByIndex = [];

            $lastUpdateTime = 0;
            if ($streamFunction !== null) {
                $stream = $this->openAiClient->chat()->createStreamed($requestOptions);
                foreach ($stream as $response) {
                    $delta = $response->choices[0]->delta;
                    if (isset($delta->content)) {
                        $contentChunk = $delta->content;
                        $content .= $contentChunk;
                        $streamFunction($contentChunk);
                        if ($progressUpdateCallback !== null) {
                            $currentTime = microtime(true);
                            if ($currentTime - $lastUpdateTime > 3) {
                                $progressUpdateCallback(static::class, "Streaming response: (" . mb_strlen($content) . ") characters");
                                $lastUpdateTime = $currentTime;
                            }
                        }
                        if ($this->isStringStartingToRepeat($content, self::REPETITION_THRESHOLD_CHARACTERS)) {
                            echo "\nRepetition detected, aborting response\n";
                            $content .= "\n\n(Response was aborted due to repetition)";
                            // Close the stream by unsetting it and clearing references
                            $stream = null;
                            unset($stream);
                            gc_collect_cycles();
                            if ($accumulatedContent !== '') {
                                $accumulatedContent .= "\n";
                            }
                            $accumulatedContent .= $content;

                            return new CompletionResponse($accumulatedContent, $allToolCalls);
                        }
                    }

                    if (isset($delta->toolCalls)) {
                        foreach ($delta->toolCalls as $toolCallChunk) {
                            $index = $toolCallChunk->index;
                            if (!isset($toolCallsByIndex[$index])) {
                                $toolCallsByIndex[$index] = (object) [
                                    'id' => $toolCallChunk->id ?? null,
                                    'type' => 'function',
                                    'function' => (object) [
                                        'name' => $toolCallChunk->function->name ?? null,
                                        'arguments' => $toolCallChunk->function->arguments ?? '',
                                    ],
                                ];
                            } else {
                                if (isset($toolCallChunk->id)) {
                                    $toolCallsByIndex[$index]->id = $toolCallChunk->id;
                                }
                                if (isset($toolCallChunk->function->name)) {
                                    $toolCallsByIndex[$index]->function->name = $toolCallChunk->function->name;
                                }
                                if (isset($toolCallChunk->function->arguments)) {
                                    $toolCallsByIndex[$index]->function->arguments .= $toolCallChunk->function->arguments;
                                }
                            }
                        }
                    }
                }
                $toolCalls = array_values($toolCallsByIndex);
            } else {
                $result = $this->openAiClient->chat()->create($requestOptions);
                $choice0Message = $result->choices[0]->message ?? null;
                if ($choice0Message === null) {
                    throw new \Exception("Unexpected response from OpenAI: " . json_encode($result, JSON_THROW_ON_ERROR));
                }

                $content = (string) ($choice0Message->content ?? '');
                $toolCalls = $choice0Message->toolCalls ?? [];
            }

            [$contentWithoutAction, $actionToolCall] = $this->extractActionToolCallFromContent($content);
            if ($actionToolCall !== null) {
                $toolCalls[] = $actionToolCall;
            }
            $content = $contentWithoutAction;

            if ($content !== '') {
                if ($accumulatedContent !== '') {
                    $accumulatedContent .= "\n";
                }
                $accumulatedContent .= $content;
            }

            if (count($toolCalls) === 0) {
                return new CompletionResponse($accumulatedContent, $allToolCalls);
            }

            echo "Received tool calls: " . json_encode($toolCalls, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";

            if ($content !== '') {
                echo "Received non-empty content alongside tool call: " . $content . "\n";
            }

            $requestOptions['messages'][] = [
                'role' => 'assistant',
                'content' => $content,
                'tool_calls' => array_map(
                    static fn(object $toolCall): array => [
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
                $toolCallNotification = "\n>Executing `" . $functionName . "` with arguments `" . $toolCall->function->arguments . "`\n";
                $accumulatedContent .= $toolCallNotification;
                if ($streamFunction !== null) {
                    $streamFunction($toolCallNotification);
                }
                if (!isset($this->toolDefintions[$functionName])) {
                    echo "Unknown tool called: $functionName\n";
                    $toolResult = ['content' => 'Error: Unknown tool call: ' . $functionName];
                } else {
                    $functionArgs = json_decode($toolCall->function->arguments, true, 512, JSON_THROW_ON_ERROR);
                    echo "Executing tool $functionName with args " . json_encode($functionArgs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";

                    if ($progressUpdateCallback !== null) {
                        $progressUpdateCallback(static::class, "Executing tool call $functionName");
                    }

                    $toolCallExecutor = $this->toolDefintions[$functionName]->toolCallClass;

                    if ($toolCallExecutor instanceof ToolCallExecutorInterface) {
                        try {
                            $toolResult = $toolCallExecutor->executeToolCall($functionArgs);
                        } catch (\Throwable $e) {
                            echo "Error executing tool call: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
                            $toolResult = ['content' => 'tool call failed'];
                        }
                    } elseif ($toolCallExecutor instanceof MessageChainAwareToolCallExecutorInterface) {
                        try {
                            $toolResult = $toolCallExecutor->executeToolCall($functionArgs, $messageChain);
                        } catch (\Throwable $e) {
                            echo "Error executing tool call: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
                            $toolResult = ['content' => 'tool call failed'];
                        }
                    } else {
                        throw new \RuntimeException(
                            'Tool call class does not implement a supported interface: ' . get_class($toolCallExecutor)
                        );
                    }
                }
                if (isset($toolResult['automatic_output_markdown'])) {
                    if (!is_string($toolResult['automatic_output_markdown'])) {
                        throw new \RuntimeException(
                            'Tool call result automatic_output_markdown must be a string'
                        );
                    }
                    $autoOutput  = "\n\n". $toolResult['automatic_output_markdown'] . "\n\n";
                    $accumulatedContent .= $autoOutput;
                    if ($streamFunction !== null) {
                        $streamFunction($autoOutput);
                    }
                    unset($toolResult['automatic_output_markdown']);
                }

                $toolResultContent = json_encode($toolResult, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                echo "Tool call result: " . mb_substr($toolResultContent, 0, 1000) . "\n";

                $allToolCalls[] = new ToolCall(
                    $toolCall->id,
                    $toolCall->function->name,
                    $toolCall->function->arguments,
                    $toolResultContent,
                );

                $requestOptions['messages'][] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall->id,
                    'content' => $toolResultContent,
                ];
            }
        }
    }

    /**
     * @return array{0:string,1:?object}
     */
    private function extractActionToolCallFromContent(string $content): array
    {
        $trimmedContent = trim($content);
        if ($trimmedContent === '') {
            return ['', null];
        }

        $firstOpeningBracePosition = mb_strpos($trimmedContent, '{');
        if ($firstOpeningBracePosition === false) {
            return [$content, null];
        }

        $candidateJson = mb_substr($trimmedContent, $firstOpeningBracePosition);
        try {
            $decodedCandidate = json_decode($candidateJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [$content, null];
        }

        if (!is_array($decodedCandidate) || !isset($decodedCandidate['action'])) {
            return [$content, null];
        }

        $functionName = (string) $decodedCandidate['action'];
        $functionArguments = $this->normalizeActionInputToToolArguments($decodedCandidate['action_input'] ?? []);

        echo "Removing Gemma 4 action from content: " . $functionName . "\n";
        $contentWithoutAction = trim(substr($trimmedContent, 0, $firstOpeningBracePosition));

        $syntheticToolCall = (object) [
            'id' => 'action_' . bin2hex(random_bytes(8)),
            'type' => 'function',
            'function' => (object) [
                'name' => $functionName,
                'arguments' => json_encode($functionArguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ],
        ];

        return [$contentWithoutAction, $syntheticToolCall];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeActionInputToToolArguments(mixed $actionInput): array
    {
        if (is_array($actionInput)) {
            return $actionInput;
        }

        if (is_string($actionInput)) {
            $trimmedActionInput = trim($actionInput);
            if ($trimmedActionInput !== '') {
                try {
                    $trimmedActionInput = preg_replace(
                        "/'([^'\\\\]*(?:\\\\.[^'\\\\]*)*)'/",
                        '"$1"',
                        $trimmedActionInput
                    );
                    $decodedActionInput = json_decode($trimmedActionInput, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decodedActionInput)) {
                        return $decodedActionInput;
                    }
                } catch (\JsonException) {
                    // Fallback below
                }
            }

            return ['input' => $actionInput];
        }

        if ($actionInput === null) {
            return [];
        }

        return ['input' => $actionInput];
    }

    private function isStringStartingToRepeat(string $str, int $charactersToCheck): bool
    {
        if (mb_strlen($str) < $charactersToCheck) {
            return false;
        }
        $lastCharacters = mb_substr($str, -$charactersToCheck);
        $earlierPart = mb_substr($str, 0, -$charactersToCheck);

        return str_contains($earlierPart, $lastCharacters);
    }
}
