<?php

namespace Perk11\Viktor89\Assistant;

use GuzzleHttp\Client as GuzzleClient;
use OpenAI;
use OpenAI\Contracts\ClientContract;
use OpenAI\Exceptions\ErrorException;
use Perk11\Viktor89\Assistant\Compaction\CompactionKey;
use Perk11\Viktor89\Assistant\Compaction\CompactionSummaryStoreInterface;
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
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/** Uses https://github.com/openai-php/client */
class OpenAiPHPClientAssistant extends AbstractOpenAIAPiAssistant
{
    private const int REPETITION_THRESHOLD_CHARACTERS = 2048;
    private const int MAX_COMPACTION_RETRIES = 2;
    /**
     * Safety backstops for the tool-call loop. A model that keeps calling the
     * SAME tool over and over (e.g. re-generating an image it cannot visually
     * verify) is aborted after MAX_CONSECUTIVE_SAME_TOOL_USES calls in a row;
     * calling any other tool resets that counter. MAX_TOTAL_TOOL_USES is a hard
     * ceiling on the whole completion regardless of which tools are used.
     */
    private const int MAX_CONSECUTIVE_SAME_TOOL_USES = 20;
    private const int MAX_TOTAL_TOOL_USES = 150;
    private readonly ClientContract $openAiClient;
    private readonly ContextCompactor $contextCompactor;

    /**
     * @param ToolDefinition[] $toolDefintions
     */
    public function __construct(
        private readonly ?string $model,
        UserPreferenceReaderInterface $systemPromptProcessor,
        UserPreferenceReaderInterface $responseStartProcessor,
        UserPreferenceReaderInterface $editFrequencyProcessor,
        TelegramFileDownloader $telegramFileDownloader,
        private readonly AltTextProvider $altTextProvider,
        private readonly ProcessingResultExecutor $processingResultExecutor,
        int $telegramBotUserId,
        private readonly string $url,
        string $apiKey = '',
        bool $supportsImages,
        private readonly array $toolDefintions = [],
        private readonly CompactionSummaryStoreInterface $compactionStore,
        ?LoggerInterface $logger = null,
        ?ClientContract $openAiClient = null,
    ) {
        if ($openAiClient !== null) {
            $this->openAiClient = $openAiClient;
        } else {
            $openAiFactory = OpenAI::factory()
                ->withBaseUri(rtrim($url, '/'))
                ->withHttpClient(new GuzzleClient(['timeout' => 3600 * 4]));
            if ($apiKey !== '') {
                $openAiFactory->withApiKey($apiKey);
            }
            $this->openAiClient = $openAiFactory->make();
        }
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
            $logger,
        );
       $this->contextCompactor = new ContextCompactor(
           $this->createSummaryGenerator(),
           $this->logger,
           $this->compactionStore,
       );
   }

   /**
    * Builds the per-reply-chain key used to scope persisted compactions. The
     * chain root (first message id) is stable across messages within a reply
     * thread. Private chats are a single linear conversation, so they share a
     * sentinel root of 0 regardless of which message started a window.
     */
    private function compactionKeyForChain(?MessageChain $messageChain): ?CompactionKey
    {
        if ($messageChain === null) {
            return null;
        }

        $last = $messageChain->last();
        $isPrivateChat = $last->chatId > 0;
        $rootMessageId = $isPrivateChat ? 0 : (int) ($messageChain->first()->id ?? 0);

        return new CompactionKey($last->chatId, $rootMessageId);
    }

   /**
    * Returns a callable that sends a simple chat completion to the same LLM
     * and returns the response text.  Used by ContextCompactor to generate
     * summaries.
     */
    private function createSummaryGenerator(): callable
    {
        $client = $this->openAiClient;
        $model  = $this->model;

           return static function (string $prompt) use ($client, $model): string {
               $result = $client->chat()->create([
                   'model'     => $model,
                   'messages'  => [
                       ['role' => 'system', 'content' => 'You are a helpful assistant that summarizes conversations concisely.'],
                       ['role' => 'user',   'content' => $prompt],
                   ],
                   'max_tokens' => 1500,
               ]);

            $content = $result->choices[0]->message->content ?? '';
            if (is_array($content)) {
                $first = current($content);
                $content = $first['text'] ?? '';
            }

            return trim($content);
        };
    }

    public function getCompletionBasedOnContext(
        AssistantContext $assistantContext,
        ?callable $streamFunction = null,
        ?MessageChain $messageChain = null,
        ?ProgressUpdateCallback $progressUpdateCallback = null
   ): CompletionResponse {
      $compactionCount = 0;

      // Apply any compaction persisted for this reply chain so messages
      // already summarized are dropped instead of being re-summarized every
      // request. A compaction is keyed by (chatId, rootMessageId) so
      // independent threads within the same group chat stay separate. Private
      // chats are a single conversation and use a sentinel root of 0.
      $compactionKey = $this->compactionKeyForChain($messageChain);
      if ($compactionKey !== null) {
          $assistantContext = $this->contextCompactor->applyStoredCompaction($compactionKey, $assistantContext);
      }

      /** @var array<string, mixed> $requestOptions */
      $supportsTools = count($this->toolDefintions) > 0;
       $requestOptions = [
           'messages' => $assistantContext->toOpenAiMessagesArray($supportsTools),
       ];
        if ($this->model !== null) {
            $requestOptions['model'] = $this->model;
        }
        foreach ($this->toolDefintions as $toolDefinition) {
            $requestOptions['tools'][] = $toolDefinition->toArray();
        }

        $allToolCalls = [];
        $accumulator = new ResponseContentAccumulator();
        $totalToolUses = 0;
        $lastToolName = null;
        $consecutiveSameToolUses = 0;

        retry_compaction:
        try {
            $this->logger?->log(LogLevel::INFO, 'Sending OpenAI request to ' . $this->url . '...');
            $this->logger?->log(LogLevel::DEBUG, json_encode(self::summarizeBase64Images($requestOptions), JSON_UNESCAPED_UNICODE));
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger?->log(LogLevel::ERROR, 'Failed to convert context to JSON: ' . json_last_error_msg());
            }
            $this->logger?->log(LogLevel::DEBUG, 'Context roles: ' . AssistantContext::summarizeRoleSequence($requestOptions['messages']));

            while (true) {
                $statusMessage = "Waiting for LLM response";
                if (count($allToolCalls) > 0) {
                    $statusMessage .= " (" . count($allToolCalls) . " tool calls)";
                }
                if ($progressUpdateCallback !== null) {
                    $progressUpdateCallback(static::class, $statusMessage);
                } else {
                    $this->logger?->log(LogLevel::INFO, $statusMessage);
                }
                $content = '';
                /** @var array<int, object> $toolCallsByIndex */
                $toolCallsByIndex = [];

                $lastUpdateTime = 0;
                $isPrivateChat = $messageChain !== null && $messageChain->last()->chatId > 0;
                if ($streamFunction !== null) {
                    $stream = $this->openAiClient->chat()->createStreamed($requestOptions);

                $thinkingBuffer = '';
                $thinkingTagOpened = false;
                $reasoning = '';

                foreach ($stream as $response) {
                    $delta = $response->choices[0]->delta;
                    if (isset($delta->reasoningContent)) {
                        $thinkingBuffer .= $delta->reasoningContent;
                        $reasoning .= $delta->reasoningContent;

                        if ($isPrivateChat) {
                            if (!$thinkingTagOpened) {
                                $streamFunction('<tg-thinking>');
                                $thinkingTagOpened = true;
                            }
                            $streamFunction($delta->reasoningContent);
                        }
                        // group chat: buffer only, format after thinking ends
                    }

                    if (isset($delta->content)) {
                        // Flush group-chat thinking buffer as a details block before content

                        if (!$isPrivateChat && $thinkingBuffer !== '') {
                            $thinkingDetailsBlock = $this->formatThinkingAsDetailsBlock($thinkingBuffer);
                            $streamFunction($thinkingDetailsBlock);
                            $thinkingBuffer = '';
                        }
                        if ($isPrivateChat && $thinkingTagOpened) {
                            $streamFunction("\n</tg-thinking>");
                            $thinkingTagOpened = false;
                        }

                        $contentChunk = $delta->content;
                        $content .= $contentChunk;
                        $streamFunction($contentChunk);
                        if ($progressUpdateCallback !== null) {
                            $currentTime = microtime(true);
                            if ($currentTime - $lastUpdateTime > 3) {
                                $progressUpdateCallback(static::class, "Streaming response: (" . (mb_strlen(
                                                                             $accumulator->llmVisibleContent
                                                                         ) + mb_strlen($content)) . ") characters");
                                $lastUpdateTime = $currentTime;
                            }
                        }
                        if ($this->isStringStartingToRepeat($content, self::REPETITION_THRESHOLD_CHARACTERS)) {
                            $this->logger?->log(LogLevel::INFO, 'Repetition detected, aborting response');
                            $content .= "\n\n(Response was aborted due to repetition)";
                            // Close the stream by unsetting it and clearing references
                            $stream = null;
                            unset($stream);
                            gc_collect_cycles();
                            $accumulator->appendSeparatingByANewLine($content);

                            return new CompletionResponse(
                                $accumulator->llmVisibleContent, $allToolCalls, reasoning: $reasoning, displayContent: $accumulator->telegramDisplayedContent
                            );
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
                $reasoning = (string) ($choice0Message->reasoningContent ?? '');
                $toolCalls = $choice0Message->toolCalls ?? [];
            }

            [$contentWithoutAction, $actionToolCall] = $this->extractActionToolCallFromContent($content);
            if ($actionToolCall !== null) {
                $toolCalls[] = $actionToolCall;
            }
            $content = $contentWithoutAction;

            $accumulator->appendSeparatingByANewLine($content);

            if (count($toolCalls) === 0) {
                return new CompletionResponse(
                    $accumulator->llmVisibleContent, $allToolCalls, reasoning: $reasoning, displayContent: $accumulator->telegramDisplayedContent
                );
            }

            $this->logger?->log(LogLevel::DEBUG, 'Received tool calls: ' . json_encode($toolCalls, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            if ($content !== '') {
                $this->logger?->log(LogLevel::DEBUG, 'Received non-empty content alongside tool call: ' . $content);
            }

            $requestOptions['messages'][] = self::buildAssistantToolCallMessage($content, $reasoning, $toolCalls);

            foreach ($toolCalls as $toolCall) {
                $functionName = $toolCall->function->name;
                $totalToolUses++;
                if ($functionName === $lastToolName) {
                    $consecutiveSameToolUses++;
                } else {
                    $lastToolName = $functionName;
                    $consecutiveSameToolUses = 1;
                }
                $toolDefinition = $this->toolDefintions[$functionName] ?? null;
                $isSilent = $toolDefinition !== null && $toolDefinition->silent;

                // Tool-call/error notifications are streamed to Telegram as live
                // progress and added to the display track only — never to the
                // clean track, which is what gets persisted and replayed to the
                // model. The model already sees the call via the structured
                // tool_calls / tool-result messages.
                if (!$isSilent) {
                    $toolCallNotification = "\n>Executing `" . $functionName . "` with arguments `" . $toolCall->function->arguments . "`\n\n";
                    $accumulator->appendTelegramDisplayOnly($toolCallNotification);
                }

                if (!isset($this->toolDefintions[$functionName])) {
                    $this->logger?->log(LogLevel::WARNING, "Unknown tool called: $functionName");
                    $toolResult = ['content' => 'Error: Unknown tool call: ' . $functionName];
                    if ($streamFunction !== null) {
                        $streamFunction($toolCallNotification);
                        $errorNotification = "\n> ==Tool not found: $functionName==\n";
                        $accumulator->appendTelegramDisplayOnly($errorNotification);
                        $streamFunction($errorNotification);
                    }
                } else {
                    $functionArgs = json_decode($toolCall->function->arguments, true, 512, JSON_THROW_ON_ERROR);
                    $this->logger?->log(LogLevel::DEBUG, "Executing tool $functionName with args " . json_encode($functionArgs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

                    if ($progressUpdateCallback !== null) {
                        $progressUpdateCallback(static::class, "Executing $functionName");
                    }
                    if ($streamFunction !== null && !$isSilent) {
                        $streamFunction($toolCallNotification);
                    }

                    $toolCallExecutor = $this->toolDefintions[$functionName]->toolCallClass;
                    $toolCallFailed = false;

                    if ($toolCallExecutor instanceof ToolCallExecutorInterface) {
                        try {
                            $toolResult = $toolCallExecutor->executeToolCall($functionArgs);
                        } catch (\Throwable $e) {
                            $this->logger?->log(LogLevel::ERROR, "Error executing tool call: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                            $toolResult = ['content' => 'tool call failed'];
                            $toolCallFailed = true;
                        }
                    } elseif ($toolCallExecutor instanceof MessageChainAwareToolCallExecutorInterface) {
                        try {
                            $toolResult = $toolCallExecutor->executeToolCall($functionArgs, $messageChain);
                        } catch (\Throwable $e) {
                            $this->logger?->log(LogLevel::ERROR, "Error executing tool call: " . $e->getMessage() . "\n" . $e->getTraceAsString());
                            $toolResult = ['content' => 'tool call failed'];
                            $toolCallFailed = true;
                        }
                    } else {
                        throw new \RuntimeException(
                            'Tool call class does not implement a supported interface: ' . get_class($toolCallExecutor)
                        );
                    }

                    if ($toolCallFailed) {
                        $errorMsg = $e->getMessage() ?? 'tool call failed';
                        $errorNotification = "\n> ==Error in $functionName: $errorMsg==\n";
                        $accumulator->appendTelegramDisplayOnly($errorNotification);
                        if ($streamFunction !== null) {
                            $streamFunction($errorNotification);
                        }
                    }
                }

                if (isset($toolResult['automatic_output_markdown'])) {
                    if (!is_string($toolResult['automatic_output_markdown'])) {
                        throw new \RuntimeException(
                            'Tool call result automatic_output_markdown must be a string'
                        );
                    }
                    $autoOutput  = "\n\n". $toolResult['automatic_output_markdown'] . "\n\n";
                    $accumulator->append($autoOutput);
                    if ($streamFunction !== null) {
                        $this->suppressDraftUpdates = true;
                        $streamFunction($autoOutput);
                    }
                    unset($toolResult['automatic_output_markdown']);
                }

                $contextImage = $this->processContextImage($toolResult, $progressUpdateCallback);

                $toolResultContent = json_encode($toolResult, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $this->logger?->log(LogLevel::DEBUG, 'Tool call result: ' . mb_substr($toolResultContent, 0, 1000));

                $allToolCalls[] = new ToolCall(
                    $toolCall->id,
                    $toolCall->function->name,
                    $toolCall->function->arguments,
                    $toolResultContent,
                );

                $toolMessage = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall->id,
                    'content' => $toolResultContent,
                ];
                // Let a vision-capable model actually see the image it just produced
                // (e.g. to judge an image_gen_tool edit) instead of guessing.
                if ($contextImage !== null) {
                    $toolMessage['content'] = [
                        ['type' => 'text', 'text' => $toolResultContent],
                        ['type' => 'image_url', 'image_url' => ['url' => $contextImage]],
                    ];
                }
                $requestOptions['messages'][] = $toolMessage;
            }

            $abortReason = null;
            if ($consecutiveSameToolUses >= self::MAX_CONSECUTIVE_SAME_TOOL_USES) {
                $abortReason = "the same tool (`$lastToolName`) was called "
                    . self::MAX_CONSECUTIVE_SAME_TOOL_USES
                    . " times in a row";
            } elseif ($totalToolUses >= self::MAX_TOTAL_TOOL_USES) {
                $abortReason = 'the total limit of ' . self::MAX_TOTAL_TOOL_USES
                    . ' tool calls was reached';
            }
            if ($abortReason !== null) {
                $limitMessage = "\n> ==Aborting the tool-call loop: " . $abortReason
                    . ". Stopping to avoid an infinite loop.==\n";
                $this->logger?->log(LogLevel::WARNING, 'Aborting tool-call loop: ' . $abortReason . '.');
                $accumulator->appendTelegramDisplayOnly($limitMessage);
                if ($streamFunction !== null) {
                    $streamFunction($limitMessage);
                }

                return new CompletionResponse(
                    $accumulator->llmVisibleContent,
                    $allToolCalls,
                    reasoning: $reasoning,
                    displayContent: $accumulator->telegramDisplayedContent,
                );
            }
        }
        } catch (ErrorException $e) {
            if ($compactionCount >= self::MAX_COMPACTION_RETRIES || !ContextCompactor::isContextLengthError($e)) {
                $this->logger?->log(LogLevel::ERROR, 'LLM request failed. Context roles: '
                    . AssistantContext::summarizeRoleSequence($requestOptions['messages']));
                throw $e;
            }

            $this->logger?->log(LogLevel::INFO, 'Context length error caught, compacting...');
            $this->logger?->log(LogLevel::ERROR, 'Error: ' . $e->getMessage());

           $compactionCount++;
           $assistantContext = $this->contextCompactor->compact($assistantContext, $compactionKey);

           // Rebuild request options from the compacted context
            $requestOptions = [
                'messages' => $assistantContext->toOpenAiMessagesArray($supportsTools),
            ];
            if ($this->model !== null) {
                $requestOptions['model'] = $this->model;
            }
            foreach ($this->toolDefintions as $toolDefinition) {
                $requestOptions['tools'][] = $toolDefinition->toArray();
            }

            // Reset accumulators so we start fresh with compacted context
            $allToolCalls = [];
            $accumulator = new ResponseContentAccumulator();

            goto retry_compaction;
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

        $this->logger?->log(LogLevel::DEBUG, "Removing Gemma 4 action from content: " . $functionName);
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

    /**
     * Make a tool's generated image ('context_image') visible to the model so it
     * has feedback about what it just produced instead of guessing and looping:
     *  - vision assistants: returns a data URL to embed as an image_url part;
     *  - non-vision assistants: appends an auto-generated alt-text description to
     *    the tool result so the model at least knows what the image contains.
     * The raw bytes are always stripped from $toolResult so they never reach json_encode.
     */
    private function processContextImage(array &$toolResult, ?ProgressUpdateCallback $progressUpdateCallback): ?string
    {
        $image = $toolResult['context_image'] ?? null;
        unset($toolResult['context_image']);

        if (!is_string($image) || $image === '') {
            return null;
        }

        if ($this->supportsImages) {
            return 'data:image/png;base64,' . base64_encode($image);
        }

        try {
            $altText = $this->altTextProvider->generateAltTextForImageString($image, $progressUpdateCallback);
            if ($altText !== '') {
                $toolResult['generated_image_description'] = $altText;
            }
        } catch (\Throwable $e) {
            $this->logger?->log(LogLevel::ERROR, 'Failed to generate alt text for generated image: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Reconstructs the assistant message exactly as the model emitted it so
     * the conversation can be continued after a tool call. reasoning_content
     * is only included when the model actually produced reasoning.
     *
     * @param list<object> $toolCalls
     */
    private static function buildAssistantToolCallMessage(string $content, string $reasoning, array $toolCalls): array
    {
        $assistantMessage = [
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
        if ($reasoning !== '') {
            $assistantMessage['reasoning_content'] = $reasoning;
        }

        return $assistantMessage;
    }

    /**
     * Return a copy of $requestOptions with base64 image data URLs replaced by
     * a compact size note (e.g. "[base64 image, 54kb]") so logs stay readable
     * instead of dumping megabytes of base64.
     *
     * @param array<string, mixed> $requestOptions
     * @return array<string, mixed>
     */
    private static function summarizeBase64Images(array $requestOptions): array
    {
        array_walk_recursive(
            $requestOptions,
            static function (mixed &$value, mixed $key): void {
                if (!is_string($value)) {
                    return;
                }
                if (preg_match('/^data:image\/(?<format>[^;]+);base64,(?<data>.+)$/', $value, $m) !== 1) {
                    return;
                }
                $sizeKb = (int) round(strlen($m['data']) / 1024);
                $value = "[base64 image, {$sizeKb}kb]";
            },
        );

        return $requestOptions;
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
