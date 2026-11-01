<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Anthropic\Client;
use GuzzleHttp\Psr7\Response;
use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\Assistant\AnthropicAssistant;
use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\AssistantContextMessage;
use Perk11\Viktor89\Assistant\Compaction\CompactionSummary;
use Perk11\Viktor89\Assistant\Compaction\CompactionSummaryStoreInterface;
use Perk11\Viktor89\Assistant\Tool\ToolCallExecutorInterface;
use Perk11\Viktor89\Assistant\Tool\ToolCallRunner;
use Perk11\Viktor89\Assistant\Tool\ToolDefinition;
use Perk11\Viktor89\Assistant\Tool\ToolParameter;
use Perk11\Viktor89\Assistant\Tool\ToolUseLimiter;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\TelegramFileDownloader;
use Perk11\Viktor89\Test\Support\RecordingToolCallExecutor;
use Perk11\Viktor89\Test\Support\TestImages;
use Perk11\Viktor89\UserPreferenceReaderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

require_once __DIR__ . '/Support/AssistantTestFixtures.php';

#[CoversClass(AnthropicAssistant::class)]
class AnthropicAssistantTest extends TestCase
{
    public function testSendsCachingThinkingAndFallbackAndStreamsThinkingBeforeText(): void
    {
        $transport = new FakeAnthropicTransport([
            self::sse([
                self::thinkingBlock(0, 'Let me think'),
                self::textBlock(1, 'Hello!'),
            ], 'end_turn'),
        ]);
        $assistant = $this->buildAssistant($transport);
        $streamed = '';

        $completion = $assistant->getCompletionBasedOnContext(
            self::context('Hi'),
            static function (string $chunk) use (&$streamed): void {
                $streamed .= $chunk;
            },
        );

        $this->assertSame('Hello!', $completion->content);
        $this->assertSame('Let me think', $completion->reasoning);
        $this->assertStringContainsString("<summary>Thinking</summary>\nLet me think", $streamed);
        $this->assertLessThan(strpos($streamed, 'Hello!'), strpos($streamed, 'Let me think'));

        $body = $transport->requestBodies[0];
        $this->assertSame('claude-opus-5-5', $body['model']);
        $this->assertSame(['type' => 'ephemeral'], $body['cache_control']);
        $this->assertSame(['type' => 'adaptive', 'display' => 'summarized'], $body['thinking']);
        $this->assertSame(['effort' => 'high'], $body['output_config']);
        $this->assertSame('default', $body['fallbacks']);
        $this->assertSame([['type' => 'text', 'text' => 'You are a test assistant.']], $body['system']);
        $this->assertArrayNotHasKey('tools', $body);
        $this->assertStringContainsString('server-side-fallback-2026-07-01', $transport->requests[0]->getHeaderLine('anthropic-beta'));
    }

    public function testToolLoopReplaysAssistantTurnAndReturnsToolImagesToTheModel(): void
    {
        $png = TestImages::png(4, 4);
        $tool = new RecordingToolCallExecutor(['status' => 'ok', 'context_image' => $png]);
        $transport = new FakeAnthropicTransport([
            self::sse([
                self::thinkingBlock(0, 'I should draw', 'sig-1'),
                self::toolUseBlock(1, 'toolu_1', 'image_gen_tool', ['{"prompt":', '"a cat"}']),
            ], 'tool_use'),
            self::sse([self::textBlock(0, 'Done!')], 'end_turn'),
        ]);
        $assistant = $this->buildAssistant($transport, ['image_gen_tool' => self::imageTool($tool)]);

        $completion = $assistant->getCompletionBasedOnContext(self::context('Draw a cat'));

        $this->assertSame([['prompt' => 'a cat']], $tool->calls);
        $this->assertSame('Done!', $completion->content);
        $this->assertCount(1, $completion->toolCalls);
        $this->assertSame('{"prompt":"a cat"}', $completion->toolCalls[0]->arguments);

        $firstRequest = $transport->requestBodies[0];
        $this->assertSame('image_gen_tool', $firstRequest['tools'][0]['name']);
        $this->assertTrue($firstRequest['tools'][0]['eager_input_streaming']);
        $this->assertSame(['type' => 'ephemeral'], $firstRequest['tools'][0]['cache_control']);

        $messages = $transport->requestBodies[1]['messages'];
        $this->assertCount(3, $messages);
        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertSame(
            ['type' => 'thinking', 'signature' => 'sig-1', 'thinking' => 'I should draw'],
            $messages[1]['content'][0],
            'thinking blocks must be replayed unchanged',
        );
        $this->assertSame(['type' => 'tool_use', 'id' => 'toolu_1', 'input' => ['prompt' => 'a cat'], 'name' => 'image_gen_tool'], $messages[1]['content'][1]);

        $toolResult = $messages[2]['content'][0];
        $this->assertSame('tool_result', $toolResult['type']);
        $this->assertSame('toolu_1', $toolResult['tool_use_id']);
        $this->assertSame(['type' => 'text', 'text' => '{"status":"ok"}'], $toolResult['content'][0]);
        $this->assertSame(
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => base64_encode($png)]],
            $toolResult['content'][1],
        );
    }

    public function testTruncatedToolInputIsReportedAsErrorWithoutRunningTheTool(): void
    {
        $tool = new RecordingToolCallExecutor(['status' => 'ok']);
        $transport = new FakeAnthropicTransport([
            self::sse([self::toolUseBlock(0, 'toolu_1', 'image_gen_tool', ['{"prompt":'])], 'tool_use'),
            self::sse([self::textBlock(0, 'Sorry')], 'end_turn'),
        ]);
        $assistant = $this->buildAssistant($transport, ['image_gen_tool' => self::imageTool($tool)]);

        $assistant->getCompletionBasedOnContext(self::context('Draw a cat'));

        $this->assertSame([], $tool->calls);
        $toolResult = $transport->requestBodies[1]['messages'][2]['content'][0];
        $this->assertTrue($toolResult['is_error']);
        $this->assertStringContainsString('not valid JSON', $toolResult['content']);
    }

    public function testRefusalIsShownToTheUser(): void
    {
        $transport = new FakeAnthropicTransport([self::sse([], 'refusal')]);
        $assistant = $this->buildAssistant($transport);

        $completion = $assistant->getCompletionBasedOnContext(self::context('Hi'));

        $this->assertStringContainsString('declined', $completion->getDisplayContent());
        $this->assertSame('', $completion->content);
    }

    public function testRetriesAfterContextLengthError(): void
    {
        $transport = new FakeAnthropicTransport([
            self::contextLengthErrorResponse(),
            self::sse([self::textBlock(0, 'Hello!')], 'end_turn'),
        ]);
        $assistant = $this->buildAssistant($transport);

        $completion = $assistant->getCompletionBasedOnContext(self::context('Hi'));

        $this->assertSame('Hello!', $completion->content);
        $this->assertCount(2, $transport->requests);
    }

    /**
     * In group chats thinking is buffered and shown as a details block once it
     * ends; a turn that goes straight from thinking to a tool call must still
     * show it, before the tool's notice.
     */
    public function testGroupChatThinkingBeforeToolUseIsShownBeforeTheToolNotice(): void
    {
        $transport = new FakeAnthropicTransport([
            self::sse([
                self::thinkingBlock(0, 'I should draw'),
                self::toolUseBlock(1, 'toolu_1', 'image_gen_tool', ['{"prompt":"a cat"}']),
            ], 'tool_use'),
            self::sse([self::textBlock(0, 'Done!')], 'end_turn'),
        ]);
        $assistant = $this->buildAssistant($transport, ['image_gen_tool' => self::imageTool(new RecordingToolCallExecutor())]);
        $streamed = '';

        $assistant->getCompletionBasedOnContext(
            self::context('Draw a cat'),
            static function (string $chunk) use (&$streamed): void {
                $streamed .= $chunk;
            },
            self::messageChain(-100),
        );

        $this->assertStringContainsString("<details>\n<summary>Thinking</summary>\nI should draw\n</details>", $streamed);
        $this->assertLessThan(strpos($streamed, 'Executing `image_gen_tool`'), strpos($streamed, 'I should draw'));
        $this->assertStringNotContainsString('<tg-thinking>', $streamed);
    }

    public function testPrivateChatThinkingIsStreamedLiveInThinkingTag(): void
    {
        $transport = new FakeAnthropicTransport([
            self::sse([self::thinkingBlock(0, 'Let me think'), self::textBlock(1, 'Hello!')], 'end_turn'),
        ]);
        $assistant = $this->buildAssistant($transport);
        $streamed = '';

        $assistant->getCompletionBasedOnContext(
            self::context('Hi'),
            static function (string $chunk) use (&$streamed): void {
                $streamed .= $chunk;
            },
            self::messageChain(5),
        );

        $this->assertSame("<tg-thinking>Let me think\n</tg-thinking>Hello!", $streamed);
    }

    public function testRepetitionAbortStopsReadingAndClosesTheStream(): void
    {
        $repeatingDeltas = [['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]];
        for ($i = 0; $i < 600; $i++) {
            $repeatingDeltas[] = ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'all work and no play. ']];
        }
        $repeatingDeltas[] = ['type' => 'content_block_stop', 'index' => 0];
        $response = self::sse([$repeatingDeltas, self::textBlock(1, 'UNREACHED')], 'end_turn');
        $transport = new FakeAnthropicTransport([$response]);

        $completion = $this->buildAssistant($transport)->getCompletionBasedOnContext(self::context('Hi'));

        $this->assertStringEndsWith('(Response was aborted due to repetition)', $completion->content);
        $this->assertStringNotContainsString('UNREACHED', $completion->content);
        $this->assertLessThan(600 * 22, mb_strlen($completion->content));
        $this->assertFalse($response->getBody()->isReadable(), 'the HTTP stream must be closed');
    }

    public function testMaxTokensCutoffIsShownToTheUser(): void
    {
        $transport = new FakeAnthropicTransport([self::sse([self::textBlock(0, 'Partial answer')], 'max_tokens')]);

        $completion = $this->buildAssistant($transport)->getCompletionBasedOnContext(self::context('Hi'));

        $this->assertSame('Partial answer', $completion->content);
        $this->assertStringContainsString('max_tokens limit was reached', $completion->getDisplayContent());
    }

    public function testToolLoopIsAbortedWhenTheSameToolIsCalledTooManyTimesInARow(): void
    {
        $responses = [];
        for ($i = 0; $i < ToolUseLimiter::MAX_CONSECUTIVE_SAME_TOOL_USES; $i++) {
            $responses[] = self::sse([self::toolUseBlock(0, "toolu_$i", 'image_gen_tool', ['{"prompt":"a cat"}'])], 'tool_use');
        }
        $transport = new FakeAnthropicTransport($responses);
        $tool = new RecordingToolCallExecutor();
        $assistant = $this->buildAssistant($transport, ['image_gen_tool' => self::imageTool($tool)]);

        $completion = $assistant->getCompletionBasedOnContext(self::context('Draw a cat'));

        $this->assertSame(ToolUseLimiter::MAX_CONSECUTIVE_SAME_TOOL_USES, $tool->callCount);
        $this->assertCount(ToolUseLimiter::MAX_CONSECUTIVE_SAME_TOOL_USES, $transport->requests);
        $this->assertCount(ToolUseLimiter::MAX_CONSECUTIVE_SAME_TOOL_USES, $completion->toolCalls);
        $this->assertStringContainsString('Aborting the tool-call loop', $completion->getDisplayContent());
    }

    public function testStoredCompactionReplacesTheMessagesItSummarized(): void
    {
        $store = $this->createMock(CompactionSummaryStoreInterface::class);
        $store->expects($this->once())
            ->method('findLatestForChain')
            ->with(-100, 1)
            ->willReturn(new CompactionSummary(-100, 1, 'They talked about cats.', 2, time()));
        $transport = new FakeAnthropicTransport([self::sse([self::textBlock(0, 'Sure')], 'end_turn')]);
        $context = new AssistantContext();
        $context->messages = [
            self::message(true, 'Old question', 1),
            self::message(false, 'Old answer', 2),
            self::message(true, 'New question', 3),
        ];

        $this->buildAssistant($transport, compactionStore: $store)
            ->getCompletionBasedOnContext($context, messageChain: self::messageChain(-100));

        $sentMessages = json_encode($transport->requestBodies[0]['messages']);
        $this->assertStringContainsString('They talked about cats.', $sentMessages);
        $this->assertStringContainsString('New question', $sentMessages);
        $this->assertStringNotContainsString('Old question', $sentMessages);
        $this->assertStringNotContainsString('Old answer', $sentMessages);
    }

    /**
     * Restarting after a tool already ran would repeat its side effects (e.g.
     * send a second image), so the completion ends with what it has instead.
     */
    public function testContextLengthErrorAfterToolCallEndsCompletionWithoutRerunningTheTool(): void
    {
        $transport = new FakeAnthropicTransport([
            self::sse([self::textBlock(0, 'Drawing'), self::toolUseBlock(1, 'toolu_1', 'image_gen_tool', ['{"prompt":"a cat"}'])], 'tool_use'),
            self::contextLengthErrorResponse(),
        ]);
        $tool = new RecordingToolCallExecutor();
        $assistant = $this->buildAssistant($transport, ['image_gen_tool' => self::imageTool($tool)]);

        $completion = $assistant->getCompletionBasedOnContext(self::context('Draw a cat'));

        $this->assertSame(1, $tool->callCount);
        $this->assertCount(2, $transport->requests);
        $this->assertSame('Drawing', $completion->content);
        $this->assertCount(1, $completion->toolCalls);
        $this->assertStringContainsString('no longer fits', $completion->getDisplayContent());
    }

    private function buildAssistant(
        FakeAnthropicTransport $transport,
        array $tools = [],
        ?CompactionSummaryStoreInterface $compactionStore = null,
    ): AnthropicAssistant {
        $client = new Client(
            apiKey: 'test-key',
            requestOptions: ['transporter' => $transport, 'streamingTransporter' => $transport, 'maxRetries' => 0],
        );

        $altTextProvider = $this->createStub(AltTextProvider::class);

        return new AnthropicAssistant(
            AnthropicAssistant::DEFAULT_MODEL,
            $this->createStub(UserPreferenceReaderInterface::class),
            $this->createStub(UserPreferenceReaderInterface::class),
            $this->createStub(UserPreferenceReaderInterface::class),
            $this->createStub(TelegramFileDownloader::class),
            $altTextProvider,
            1,
            $compactionStore ?? $this->createStub(CompactionSummaryStoreInterface::class),
            new ToolCallRunner($altTextProvider, new NullLogger()),
            $tools,
            effort: 'high',
            logger: new NullLogger(),
            client: $client,
        );
    }

    private static function imageTool(ToolCallExecutorInterface $executor): ToolDefinition
    {
        return new ToolDefinition(
            'image_gen_tool',
            $executor,
            'Generate an image',
            [new ToolParameter('prompt', ['type' => 'string'], true)],
        );
    }

    private static function context(string $text): AssistantContext
    {
        $context = new AssistantContext();
        $context->systemPrompt = 'You are a test assistant.';
        $context->messages[] = self::message(true, $text);

        return $context;
    }

    private static function message(bool $isUser, string $text, ?int $messageId = null): AssistantContextMessage
    {
        $message = new AssistantContextMessage();
        $message->isUser = $isUser;
        $message->text = $text;
        $message->messageId = $messageId;

        return $message;
    }

    /** Negative chat ids are group chats, positive ones private chats. */
    private static function messageChain(int $chatId): MessageChain
    {
        $message = new InternalMessage();
        $message->id = 1;
        $message->chatId = $chatId;
        $message->userId = 5;
        $message->messageText = 'Hi';

        return new MessageChain([$message]);
    }

    private static function contextLengthErrorResponse(): Response
    {
        return new Response(400, ['Content-Type' => 'application/json'], json_encode([
            'type' => 'error',
            'error' => ['type' => 'invalid_request_error', 'message' => 'prompt is too long: 1000001 tokens > 1000000 maximum'],
        ]));
    }

    /** @return list<array<string, mixed>> */
    private static function thinkingBlock(int $index, string $thinking, string $signature = 'sig'): array
    {
        return [
            ['type' => 'content_block_start', 'index' => $index, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']],
            ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'thinking_delta', 'thinking' => $thinking]],
            ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'signature_delta', 'signature' => $signature]],
            ['type' => 'content_block_stop', 'index' => $index],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function textBlock(int $index, string $text): array
    {
        return [
            ['type' => 'content_block_start', 'index' => $index, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'text_delta', 'text' => $text]],
            ['type' => 'content_block_stop', 'index' => $index],
        ];
    }

    /**
     * @param list<string> $jsonChunks
     * @return list<array<string, mixed>>
     */
    private static function toolUseBlock(int $index, string $id, string $name, array $jsonChunks): array
    {
        $events = [['type' => 'content_block_start', 'index' => $index, 'content_block' => ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => new \stdClass()]]];
        foreach ($jsonChunks as $chunk) {
            $events[] = ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'input_json_delta', 'partial_json' => $chunk]];
        }
        $events[] = ['type' => 'content_block_stop', 'index' => $index];

        return $events;
    }

    /** @param list<list<array<string, mixed>>> $blocks */
    private static function sse(array $blocks, string $stopReason): Response
    {
        $events = [[
            'type' => 'message_start',
            'message' => [
                'id' => 'msg_' . bin2hex(random_bytes(4)),
                'type' => 'message',
                'role' => 'assistant',
                'model' => AnthropicAssistant::DEFAULT_MODEL,
                'content' => [],
                'stop_reason' => null,
                'stop_sequence' => null,
                'usage' => ['input_tokens' => 10, 'output_tokens' => 1],
            ],
        ]];
        array_push($events, ...array_merge(...$blocks));
        $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $stopReason, 'stop_sequence' => null], 'usage' => ['output_tokens' => 5]];
        $events[] = ['type' => 'message_stop'];

        $body = '';
        foreach ($events as $event) {
            $body .= 'event: ' . $event['type'] . "\ndata: " . json_encode($event) . "\n\n";
        }

        return new Response(200, ['Content-Type' => 'text/event-stream'], $body);
    }
}

class FakeAnthropicTransport implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];
    /** @var list<array<string, mixed>> */
    public array $requestBodies = [];

    /** @param list<ResponseInterface> $responses */
    public function __construct(private array $responses)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $this->requestBodies[] = json_decode((string) $request->getBody(), true);
        if ($this->responses === []) {
            throw new \RuntimeException('No fake responses left');
        }

        return array_shift($this->responses);
    }
}
