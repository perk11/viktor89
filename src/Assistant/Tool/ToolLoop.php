<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool;

use Amp\Future;
use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\Compaction\CompactionKey;
use Perk11\Viktor89\Assistant\CompletionResponse;
use Perk11\Viktor89\Assistant\ContextCompactor;
use Perk11\Viktor89\Assistant\ContextLengthErrorDetector;
use Perk11\Viktor89\Assistant\MessageConverterInterface;
use Perk11\Viktor89\Assistant\ResponseContentAccumulator;
use Perk11\Viktor89\Assistant\StreamingTurnPresenter;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\Util\BackgroundFiber;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * The tool-call loop of the assistants that execute tools themselves: request
 * a turn, run the tools it asked for concurrently, append them to the
 * conversation and repeat until the model answers without tool calls. The
 * API-specific parts are the two callbacks passed to run().
 *
 * A context-length error on the first request compacts the context and starts
 * over. Once tools have run, starting over would repeat their side effects
 * and the output already streamed, so the completion ends with what it has.
 */
final class ToolLoop
{
    private const int MAX_COMPACTION_RETRIES = 2;
    private const string CONTEXT_EXHAUSTED_NOTICE = "\n> ==Stopped: the conversation no longer fits in the model's context window.==\n";

    /**
     * @param array<string, ToolDefinition> $toolDefinitions
     */
    public function __construct(
        private readonly MessageConverterInterface $messageConverter,
        private readonly ToolCallRunner $toolCallRunner,
        private readonly ContextCompactor $contextCompactor,
        private readonly array $toolDefinitions,
        private readonly bool $supportsImages,
        private readonly string $processorName,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param \Closure(array $conversation, StreamingTurnPresenter $presenter): ModelTurn $requestTurn
     *        sends the conversation to the model, streaming the reply through the presenter
     * @param \Closure(array $conversation, ModelTurn $turn, list<ToolCallRunResult> $results): array $appendToolRound
     *        returns the conversation with the turn and its tool results appended
     */
    public function run(
        AssistantContext $context,
        \Closure $requestTurn,
        \Closure $appendToolRound,
        ?callable $streamFunction,
        ?MessageChain $messageChain,
        ?ProgressUpdateCallback $progressUpdateCallback,
    ): CompletionResponse {
        // A compaction persisted for this reply chain replaces the messages it
        // already summarized, so they are not re-summarized on every request.
        $compactionKey = CompactionKey::forMessageChain($messageChain);
        if ($compactionKey !== null) {
            $context = $this->contextCompactor->applyStoredCompaction($compactionKey, $context);
        }
        $toolCallContext = new ToolCallContext(
            new ResponseContentAccumulator(),
            $streamFunction === null ? null : $streamFunction(...),
            $messageChain,
            $progressUpdateCallback,
        );
        $isPrivateChat = $messageChain !== null && $messageChain->last()->chatId > 0;

        for ($compactionCount = 0; ; $compactionCount++) {
            $conversation = $this->messageConverter->convert($context, $this->toolDefinitions !== []);
            try {
                return $this->runRounds($conversation, $requestTurn, $appendToolRound, $toolCallContext, $isPrivateChat);
            } catch (\Throwable $e) {
                if ($compactionCount >= self::MAX_COMPACTION_RETRIES || !ContextLengthErrorDetector::isContextLengthError($e)) {
                    throw $e;
                }
                $this->logger?->log(LogLevel::INFO, 'Context length error caught, compacting: ' . $e->getMessage());
                $context = $this->contextCompactor->compact($context, $compactionKey);
            }
        }
    }

    private function runRounds(
        array $conversation,
        \Closure $requestTurn,
        \Closure $appendToolRound,
        ToolCallContext $toolCallContext,
        bool $isPrivateChat,
    ): CompletionResponse {
        $accumulator = $toolCallContext->accumulator;
        $executedToolCalls = [];
        $reasoning = '';
        $toolUseLimiter = new ToolUseLimiter();
        $complete = static function () use ($accumulator, &$executedToolCalls, &$reasoning): CompletionResponse {
            return $accumulator->toCompletionResponse($executedToolCalls, $reasoning === '' ? null : $reasoning);
        };

        while (true) {
            $this->reportStatus($toolCallContext->progressUpdateCallback, count($executedToolCalls));
            $presenter = new StreamingTurnPresenter(
                $isPrivateChat,
                $toolCallContext->streamFunction,
                $toolCallContext->progressUpdateCallback,
                $this->processorName,
                mb_strlen($accumulator->llmVisibleContent),
            );
            try {
                $turn = $requestTurn($conversation, $presenter);
            } catch (\Throwable $e) {
                if ($executedToolCalls === [] || !ContextLengthErrorDetector::isContextLengthError($e)) {
                    throw $e;
                }
                $this->logger?->log(LogLevel::WARNING, 'Context length exceeded after tool calls, ending the completion: ' . $e->getMessage());
                $toolCallContext->notify(self::CONTEXT_EXHAUSTED_NOTICE);

                return $complete();
            }

            if ($turn->reasoning !== '') {
                $reasoning .= ($reasoning === '' ? '' : "\n\n") . $turn->reasoning;
            }
            $accumulator->appendSeparatingByANewLine($turn->text);

            if ($turn->abortedForRepetition) {
                $this->logger?->log(LogLevel::INFO, 'Repetition detected, response aborted');
                return $complete();
            }
            if ($turn->stopNotice !== null) {
                $toolCallContext->notify($turn->stopNotice);
                return $complete();
            }
            if ($turn->toolCalls === []) {
                return $complete();
            }

            foreach ($turn->toolCalls as $toolCall) {
                $toolUseLimiter->record($toolCall->name);
            }
            $results = $this->runToolCalls($turn->toolCalls, $toolCallContext);
            foreach ($results as $result) {
                $executedToolCalls[] = $result->toolCall;
            }
            $conversation = $appendToolRound($conversation, $turn, $results);

            $abortReason = $toolUseLimiter->limitReachedReason();
            if ($abortReason !== null) {
                $this->logger?->log(LogLevel::WARNING, "Aborting tool-call loop: $abortReason.");
                $toolCallContext->notify("\n> ==Aborting the tool-call loop: $abortReason. Stopping to avoid an infinite loop.==\n");

                return $complete();
            }
        }
    }

    /**
     * The calls of one turn were all requested before any of them ran, so they
     * run concurrently, each in a BackgroundFiber. A call lets the others
     * progress while it waits on I/O that yields to the event loop (HTTP
     * through FiberYieldingCurlHandler, IPC); other work still blocks.
     *
     * @param list<ToolCall> $toolCalls
     * @return list<ToolCallRunResult> in the order of $toolCalls
     */
    private function runToolCalls(array $toolCalls, ToolCallContext $toolCallContext): array
    {
        $progress = count($toolCalls) > 1 && $toolCallContext->progressUpdateCallback !== null
            ? new ConcurrentToolCallProgress($toolCallContext->progressUpdateCallback, $toolCalls)
            : null;
        $futures = [];
        foreach ($toolCalls as $index => $toolCall) {
            $context = $progress === null
                ? $toolCallContext
                : $toolCallContext->withProgressUpdateCallback($progress->callbackFor($index));
            $futures[$index] = BackgroundFiber::start(function () use ($toolCall, $context, $progress, $index): ToolCallRunResult {
                try {
                    return $this->toolCallRunner->run(
                        $toolCall,
                        $this->toolDefinitions[$toolCall->name] ?? null,
                        $context,
                        $this->supportsImages ? $this->messageConverter : null,
                    );
                } finally {
                    $progress?->finish($index);
                }
            });
        }
        // Wait for every call before rethrowing, so none keeps running after the loop gives up.
        [$errors, $results] = Future\awaitAll($futures);
        if ($errors !== []) {
            throw reset($errors);
        }

        return array_map(static fn (int $index): ToolCallRunResult => $results[$index], array_keys($toolCalls));
    }

    private function reportStatus(?ProgressUpdateCallback $progressUpdateCallback, int $toolCallCount): void
    {
        $statusMessage = 'Waiting for LLM response';
        if ($toolCallCount > 0) {
            $statusMessage .= " ($toolCallCount tool calls)";
        }
        if ($progressUpdateCallback !== null) {
            $progressUpdateCallback($this->processorName, $statusMessage);
        } else {
            $this->logger?->log(LogLevel::INFO, $statusMessage);
        }
    }
}
