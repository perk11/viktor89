<?php

namespace Perk11\Viktor89\Assistant;

use GuzzleHttp\Client as GuzzleClient;
use OpenAI;
use OpenAI\Contracts\ClientContract;
use OpenAI\Exceptions\ErrorException;
use Perk11\Viktor89\Assistant\Compaction\CompactionSummaryStoreInterface;
use Perk11\Viktor89\Assistant\Tool\ModelTurn;
use Perk11\Viktor89\Assistant\Tool\ToolCall;
use Perk11\Viktor89\Assistant\Tool\ToolCallRunner;
use Perk11\Viktor89\Assistant\Tool\ToolCallRunResult;
use Perk11\Viktor89\Assistant\Tool\ToolDefinition;
use Perk11\Viktor89\Assistant\Tool\ToolLoop;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\ProcessingResultExecutor;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

/** Uses https://github.com/openai-php/client */
class OpenAiPHPClientAssistant extends AbstractStreamingAssistant
{
    private const int SUMMARY_MAX_TOKENS = 1500;

    private readonly ClientContract $openAiClient;
    /** @var list<array<string, mixed>> */
    private readonly array $tools;
    private readonly ToolLoop $toolLoop;

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
        string $apiKey,
        bool $supportsImages,
        array $toolDefintions,
        CompactionSummaryStoreInterface $compactionStore,
        ToolCallRunner $toolCallRunner,
        ?LoggerInterface $logger = null,
        ?ClientContract $openAiClient = null,
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
        $this->openAiClient = $openAiClient ?? self::createClient($url, $apiKey);
        $this->tools = array_values(array_map(
            static fn (ToolDefinition $toolDefinition): array => $toolDefinition->toArray(),
            $toolDefintions,
        ));
        $this->toolLoop = new ToolLoop(
            new OpenAiMessageConverter($logger),
            $toolCallRunner,
            new ContextCompactor($this->createSummaryGenerator(), $logger ?? new NullLogger(), $compactionStore),
            $toolDefintions,
            $supportsImages,
            static::class,
            $logger,
        );
    }

    private static function createClient(string $url, string $apiKey): ClientContract
    {
        $openAiFactory = OpenAI::factory()
            ->withBaseUri(rtrim($url, '/'))
            ->withHttpClient(new GuzzleClient(['timeout' => 3600 * 4]));
        if ($apiKey !== '') {
            $openAiFactory->withApiKey($apiKey);
        }

        return $openAiFactory->make();
    }

    /**
     * Returns a callable that sends a simple chat completion to the same LLM
     * and returns the response text. Used by ContextCompactor to generate
     * summaries.
     */
    private function createSummaryGenerator(): \Closure
    {
        $client = $this->openAiClient;
        $model  = $this->model;

        return static function (string $prompt) use ($client, $model): string {
            $result = $client->chat()->create([
                'model'      => $model,
                'messages'   => [
                    ['role' => 'system', 'content' => ContextCompactor::SUMMARY_SYSTEM_PROMPT],
                    ['role' => 'user',   'content' => $prompt],
                ],
                'max_tokens' => self::SUMMARY_MAX_TOKENS,
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
        $stream = $streamFunction !== null;

        return $this->toolLoop->run(
            $assistantContext,
            fn (array $messages, StreamingTurnPresenter $presenter): ModelTurn => $this->requestTurn($messages, $stream, $presenter),
            $this->appendToolRound(...),
            $streamFunction,
            $messageChain,
            $progressUpdateCallback,
        );
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function requestTurn(array $messages, bool $stream, StreamingTurnPresenter $presenter): ModelTurn
    {
        $request = ['messages' => $messages];
        if ($this->model !== null) {
            $request['model'] = $this->model;
        }
        if ($this->tools !== []) {
            $request['tools'] = $this->tools;
        }

        $this->logger?->log(LogLevel::INFO, 'Sending OpenAI request to ' . $this->url . '...');
        $this->logger?->log(LogLevel::DEBUG, json_encode(self::summarizeBase64Images($request), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        try {
            $turn = $stream ? $this->streamTurn($request, $presenter) : $this->createTurn($request);
        } catch (ErrorException $e) {
            $this->logger?->log(LogLevel::ERROR, 'LLM request failed. Context roles: ' . OpenAiMessageConverter::summarizeRoleSequence($messages));
            throw $e;
        }
        if ($turn->abortedForRepetition) {
            return $turn;
        }

        [$text, $actionToolCall] = $this->extractActionToolCallFromContent($turn->text);

        return new ModelTurn(
            $text,
            $turn->reasoning,
            $actionToolCall === null ? $turn->toolCalls : [...$turn->toolCalls, $actionToolCall],
        );
    }

    private function streamTurn(array $request, StreamingTurnPresenter $presenter): ModelTurn
    {
        $stream = $this->openAiClient->chat()->createStreamed($request);
        /** @var array<int, ToolCall> $toolCallsByIndex */
        $toolCallsByIndex = [];

        foreach ($stream as $response) {
            $delta = $response->choices[0]->delta;
            if (isset($delta->reasoningContent)) {
                $presenter->thinking($delta->reasoningContent);
            }
            if (isset($delta->content) && $presenter->text($delta->content)) {
                // Dropping the stream closes the connection, which stops the generation.
                unset($stream, $response);
                gc_collect_cycles();

                return new ModelTurn($presenter->text, $presenter->reasoning, abortedForRepetition: true);
            }
            foreach ($delta->toolCalls ?? [] as $toolCallChunk) {
                $toolCall = $toolCallsByIndex[$toolCallChunk->index] ??= new ToolCall('', '', '');
                if (isset($toolCallChunk->id)) {
                    $toolCall->id = $toolCallChunk->id;
                }
                if (isset($toolCallChunk->function->name)) {
                    $toolCall->name = $toolCallChunk->function->name;
                }
                if (isset($toolCallChunk->function->arguments)) {
                    $toolCall->arguments .= $toolCallChunk->function->arguments;
                }
            }
        }
        $presenter->endThinking();

        return new ModelTurn($presenter->text, $presenter->reasoning, array_values($toolCallsByIndex));
    }

    private function createTurn(array $request): ModelTurn
    {
        $result = $this->openAiClient->chat()->create($request);
        $message = $result->choices[0]->message ?? null;
        if ($message === null) {
            throw new \Exception("Unexpected response from OpenAI: " . json_encode($result, JSON_THROW_ON_ERROR));
        }

        return new ModelTurn(
            (string) ($message->content ?? ''),
            (string) ($message->reasoningContent ?? ''),
            array_map(
                static fn (object $toolCall): ToolCall => new ToolCall($toolCall->id, $toolCall->function->name, $toolCall->function->arguments),
                $message->toolCalls ?? [],
            ),
        );
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param list<ToolCallRunResult> $results
     * @return list<array<string, mixed>>
     */
    private function appendToolRound(array $messages, ModelTurn $turn, array $results): array
    {
        $messages[] = self::buildAssistantToolCallMessage($turn->text, $turn->reasoning, $turn->toolCalls);
        foreach ($results as $result) {
            $content = $result->toolCall->result;
            $messages[] = [
                'role'         => 'tool',
                'tool_call_id' => $result->toolCall->id,
                // Let a vision-capable model actually see the images a tool produced
                // or found (e.g. to judge an image_gen_tool edit) instead of guessing.
                'content'      => $result->imageParts === []
                    ? $content
                    : [['type' => 'text', 'text' => $content], ...$result->imageParts],
            ];
        }

        return $messages;
    }

    /**
     * @return array{0: string, 1: ?ToolCall}
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

        $syntheticToolCall = new ToolCall(
            'action_' . bin2hex(random_bytes(8)),
            $functionName,
            json_encode($functionArguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

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
     * Reconstructs the assistant message exactly as the model emitted it so
     * the conversation can be continued after a tool call. reasoning_content
     * is only included when the model actually produced reasoning.
     *
     * @param list<ToolCall> $toolCalls
     */
    private static function buildAssistantToolCallMessage(string $content, string $reasoning, array $toolCalls): array
    {
        $assistantMessage = [
            'role' => 'assistant',
            'content' => $content,
            'tool_calls' => array_map(
                static fn(ToolCall $toolCall): array => [
                    'id' => $toolCall->id,
                    'type' => 'function',
                    'function' => [
                        'name' => $toolCall->name,
                        'arguments' => $toolCall->arguments,
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
}
