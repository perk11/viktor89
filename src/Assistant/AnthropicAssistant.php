<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant;

use Anthropic\Client;
use Anthropic\Lib\Streaming\MessageAccumulator;
use Perk11\Viktor89\Assistant\Compaction\CompactionSummaryStoreInterface;
use Perk11\Viktor89\Assistant\Tool\ModelTurn;
use Perk11\Viktor89\Assistant\Tool\ToolCall;
use Perk11\Viktor89\Assistant\Tool\ToolCallRunner;
use Perk11\Viktor89\Assistant\Tool\ToolCallRunResult;
use Perk11\Viktor89\Assistant\Tool\ToolDefinition;
use Perk11\Viktor89\Assistant\Tool\ToolLoop;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

/** Claude via the Anthropic Messages API, using https://github.com/anthropics/anthropic-sdk-php */
class AnthropicAssistant extends AbstractStreamingAssistant
{
    public const string DEFAULT_MODEL = 'claude-opus-5-5';
    public const int DEFAULT_MAX_TOKENS = 64000;
    private const int SUMMARY_MAX_TOKENS = 16000;
    private const float REQUEST_TIMEOUT_SECONDS = 3600;
    private const string FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    private readonly Client $client;
    /** @var list<array<string, mixed>> */
    private readonly array $tools;
    private readonly ToolLoop $toolLoop;

    /**
     * @param ToolDefinition[] $toolDefinitions
     * @param string|null $effort low|medium|high|xhigh|max, null for the model default
     */
    public function __construct(
        private readonly string $model,
        UserPreferenceReaderInterface $systemPromptProcessor,
        UserPreferenceReaderInterface $responseStartProcessor,
        UserPreferenceReaderInterface $editFrequencyProcessor,
        TelegramFileDownloader $telegramFileDownloader,
        AltTextProvider $altTextProvider,
        int $telegramBotUserId,
        CompactionSummaryStoreInterface $compactionStore,
        ToolCallRunner $toolCallRunner,
        array $toolDefinitions = [],
        string $apiKey = '',
        ?string $baseUrl = null,
        bool $supportsImages = true,
        private readonly ?string $effort = null,
        private readonly int $maxTokens = self::DEFAULT_MAX_TOKENS,
        private readonly bool $thinking = true,
        ?LoggerInterface $logger = null,
        ?Client $client = null,
    ) {
        parent::__construct(
            $systemPromptProcessor,
            $responseStartProcessor,
            $editFrequencyProcessor,
            $telegramFileDownloader,
            $altTextProvider,
            $telegramBotUserId,
            $supportsImages,
            $logger,
        );
        // An empty key lets the SDK resolve credentials itself (ANTHROPIC_API_KEY, `ant auth login` profile, ...).
        $this->client = $client ?? new Client(
            apiKey: $apiKey === '' ? null : $apiKey,
            baseUrl: $baseUrl,
            requestOptions: ['timeout' => self::REQUEST_TIMEOUT_SECONDS],
        );
        $this->tools = self::buildTools($toolDefinitions);
        $this->toolLoop = new ToolLoop(
            new AnthropicMessageConverter($logger),
            $toolCallRunner,
            new ContextCompactor($this->createSummaryGenerator(), $logger ?? new NullLogger(), $compactionStore),
            $toolDefinitions,
            $supportsImages,
            static::class,
            $logger,
        );
    }

    private function createSummaryGenerator(): \Closure
    {
        $client = $this->client;
        $model = $this->model;

        return static function (string $prompt) use ($client, $model): string {
            $message = $client->messages->create(
                maxTokens: self::SUMMARY_MAX_TOKENS,
                messages: [['role' => 'user', 'content' => $prompt]],
                model: $model,
                outputConfig: ['effort' => 'low'],
                system: ContextCompactor::SUMMARY_SYSTEM_PROMPT,
            );

            $summary = '';
            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    $summary .= $block->text;
                }
            }

            return trim($summary);
        };
    }

    public function getCompletionBasedOnContext(
        AssistantContext $assistantContext,
        ?callable $streamFunction = null,
        ?MessageChain $messageChain = null,
        ?ProgressUpdateCallback $progressUpdateCallback = null
    ): CompletionResponse {
        return $this->toolLoop->run(
            $assistantContext,
            $this->requestTurn(...),
            $this->appendToolRound(...),
            $streamFunction,
            $messageChain,
            $progressUpdateCallback,
        );
    }

    /**
     * @param array{system: ?string, messages: list<array<string, mixed>>} $conversation
     */
    private function requestTurn(array $conversation, StreamingTurnPresenter $presenter): ModelTurn
    {
        $request = [
            'maxTokens'    => $this->maxTokens,
            'messages'     => $conversation['messages'],
            'model'        => $this->model,
            // Automatic caching puts the breakpoint on the last block, so every
            // round of the tool loop and every later reply in the thread reads
            // the earlier conversation from the cache.
            'cacheControl' => ['type' => 'ephemeral'],
            'fallbacks'    => 'default',
            'betas'        => [self::FALLBACK_BETA],
        ];
        if ($conversation['system'] !== null) {
            $request['system'] = [['type' => 'text', 'text' => $conversation['system']]];
        }
        if ($this->tools !== []) {
            $request['tools'] = $this->tools;
        }
        if ($this->thinking) {
            $request['thinking'] = ['type' => 'adaptive', 'display' => 'summarized'];
        }
        if ($this->effort !== null) {
            $request['outputConfig'] = ['effort' => $this->effort];
        }

        $this->logger?->log(LogLevel::INFO, 'Sending Anthropic request for model ' . $this->model . '...');
        $this->logger?->log(LogLevel::DEBUG, json_encode(self::summarizeBase64Images($request), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));

        $stream = $this->client->beta->messages->createStream(...$request);
        $messageAccumulator = MessageAccumulator::forBetaMessages();
        $toolUseIdByIndex = [];
        $toolInputs = [];

        foreach ($stream as $event) {
            $messageAccumulator->accumulate($event);

            if ($event->type === 'content_block_start') {
                if ($event->contentBlock->type !== 'thinking') {
                    $presenter->endThinking();
                }
                if ($event->contentBlock->type === 'tool_use') {
                    $toolUseIdByIndex[$event->index] = $event->contentBlock->id;
                    $toolInputs[$event->contentBlock->id] = '';
                }
                continue;
            }
            if ($event->type !== 'content_block_delta') {
                continue;
            }

            $delta = $event->delta;
            if ($delta->type === 'thinking_delta') {
                $presenter->thinking($delta->thinking);
            } elseif ($delta->type === 'input_json_delta' && isset($toolUseIdByIndex[$event->index])) {
                $toolInputs[$toolUseIdByIndex[$event->index]] .= $delta->partialJSON;
            } elseif ($delta->type === 'text_delta' && $presenter->text($delta->text)) {
                // Otherwise the model keeps generating (and billing) up to maxTokens.
                $stream->close();

                return new ModelTurn($presenter->text, $presenter->reasoning, abortedForRepetition: true);
            }
        }
        $presenter->endThinking();
        $message = $messageAccumulator->message();

        $stopNotice = match ($message->stopReason) {
            'refusal' => "\n> ==The model declined to continue this response.==\n",
            'max_tokens' => "\n> ==The response was cut off: the max_tokens limit was reached.==\n",
            default => null,
        };
        if ($stopNotice !== null) {
            $this->logger?->log(LogLevel::WARNING, 'Anthropic response stopped: ' . $message->stopReason
                . ($message->stopDetails !== null ? ' ' . json_encode($message->stopDetails) : ''));
        }

        $toolCalls = [];
        foreach ($message->content as $block) {
            if ($block->type === 'tool_use') {
                $arguments = $toolInputs[$block->id] ?? '';
                $toolCalls[] = new ToolCall($block->id, $block->name, trim($arguments) === '' ? '{}' : $arguments);
            }
        }

        return new ModelTurn($presenter->text, $presenter->reasoning, $toolCalls, stopNotice: $stopNotice, replay: $message->content);
    }

    /**
     * @param array{system: ?string, messages: list<array<string, mixed>>} $conversation
     * @param list<ToolCallRunResult> $results
     * @return array{system: ?string, messages: list<array<string, mixed>>}
     */
    private function appendToolRound(array $conversation, ModelTurn $turn, array $results): array
    {
        // The assistant turn is replayed exactly as received (thinking
        // blocks and their signatures included), which keeps the request
        // append-only for prompt caching and preserved thinking.
        $conversation['messages'][] = ['role' => 'assistant', 'content' => $turn->replay];
        $conversation['messages'][] = [
            'role'    => 'user',
            'content' => array_map(static function (ToolCallRunResult $result): array {
                $toolResult = [
                    'type'      => 'tool_result',
                    'toolUseID' => $result->toolCall->id,
                    // Let a vision-capable model actually see the images a tool produced
                    // or found (e.g. to judge an image_gen_tool edit) instead of guessing.
                    'content'   => $result->imageParts === []
                        ? $result->toolCall->result
                        : [['type' => 'text', 'text' => $result->toolCall->result], ...$result->imageParts],
                ];
                if ($result->failed) {
                    $toolResult['isError'] = true;
                }

                return $toolResult;
            }, $results),
        ];

        return $conversation;
    }

    /**
     * @param ToolDefinition[] $toolDefinitions
     * @return list<array<string, mixed>>
     */
    private static function buildTools(array $toolDefinitions): array
    {
        $tools = [];
        foreach ($toolDefinitions as $toolDefinition) {
            $function = $toolDefinition->toArray()['function'];
            $tool = [
                'name'                => $function['name'],
                'inputSchema'         => $function['parameters'],
                'eagerInputStreaming' => true,
            ];
            if (isset($function['description'])) {
                $tool['description'] = $function['description'];
            }
            $tools[] = $tool;
        }
        if ($tools !== []) {
            // Second breakpoint: the tool list is identical for every chat
            // and user, so it stays cached even when the system prompt changes.
            $tools[array_key_last($tools)]['cacheControl'] = ['type' => 'ephemeral'];
        }

        return $tools;
    }
}
