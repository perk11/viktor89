<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use OpenAI\Responses\Chat\CreateStreamedResponse;
use OpenAI\Testing\ClientFake;
use Perk11\Viktor89\Assistant\AltTextProvider;
use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\AssistantContextMessage;
use Perk11\Viktor89\Assistant\OpenAiPHPClientAssistant;
use Perk11\Viktor89\Assistant\Tool\ToolCallExecutorInterface;
use Perk11\Viktor89\Assistant\Tool\ToolDefinition;
use Perk11\Viktor89\Assistant\Tool\ToolParameter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

#[CoversClass(\Perk11\Viktor89\Assistant\OpenAiPHPClientAssistant::class)]
class OpenAiPHPClientAssistantTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\OpenAiPHPClientAssistant::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testExtendsAbstractOpenAiApiAssistant(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\OpenAiPHPClientAssistant::class);
        $parent = $reflection->getParentClass();
        $this->assertNotNull($parent);
        $this->assertSame(\Perk11\Viktor89\Assistant\AbstractOpenAIAPiAssistant::class, $parent->getName());
    }

    public function testImplementsAssistantInterface(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\OpenAiPHPClientAssistant::class);
        $this->assertTrue(
            $reflection->implementsInterface(\Perk11\Viktor89\Assistant\AssistantInterface::class)
        );
    }

    public function testHasGetCompletionBasedOnContextMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\OpenAiPHPClientAssistant::class);
        $method = $reflection->getMethod('getCompletionBasedOnContext');
        $this->assertFalse($method->isAbstract());
    }

    /**
     * The echoed-back assistant message must faithfully carry the model's
     * reasoning under the spec key (reasoning_content) when the model produced
     * any. Regression for the bug where it was emitted under the wrong key
     * "reasoning" and always added (breaking strict OpenAI-compatible endpoints).
     */
    public function testBuildAssistantToolCallMessageIncludesReasoningContentWhenNonEmpty(): void
    {
        $toolCall = $this->buildToolCallObject('call_1', 'image_gen_tool', '{"prompt":"a cat"}');

        $message = $this->invokeBuildAssistantToolCallMessage('', 'let me think about cats', [$toolCall]);

        $this->assertSame('assistant', $message['role']);
        $this->assertSame('', $message['content']);
        $this->assertArrayHasKey('reasoning_content', $message);
        $this->assertSame('let me think about cats', $message['reasoning_content']);
        // The non-standard "reasoning" key must never be emitted.
        $this->assertArrayNotHasKey('reasoning', $message);
    }

    /**
     * Models that produce no reasoning (e.g. non-thinking endpoints) must not
     * get an empty reasoning_content key, since strict endpoints reject it.
     */
    public function testBuildAssistantToolCallMessageOmitsReasoningContentWhenEmpty(): void
    {
        $toolCall = $this->buildToolCallObject('call_1', 'image_gen_tool', '{"prompt":"a cat"}');

        $message = $this->invokeBuildAssistantToolCallMessage('done', '', [$toolCall]);

        $this->assertArrayNotHasKey('reasoning_content', $message);
        $this->assertArrayNotHasKey('reasoning', $message);
        $this->assertSame('done', $message['content']);
    }

    public function testBuildAssistantToolCallMessageReconstructsToolCallsFaithfully(): void
    {
        $toolCall1 = $this->buildToolCallObject('call_1', 'image_gen_tool', '{"prompt":"a cat"}');
        $toolCall2 = $this->buildToolCallObject('call_2', 'list_chain_images', '{}');

        $message = $this->invokeBuildAssistantToolCallMessage('', '', [$toolCall1, $toolCall2]);

        $this->assertCount(2, $message['tool_calls']);
        $this->assertSame(
            ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'image_gen_tool', 'arguments' => '{"prompt":"a cat"}']],
            $message['tool_calls'][0],
        );
        $this->assertSame(
            ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'list_chain_images', 'arguments' => '{}']],
            $message['tool_calls'][1],
        );
    }

    private function buildToolCallObject(string $id, string $name, string $arguments): object
    {
        return (object) [
            'id' => $id,
            'type' => 'function',
            'function' => (object) [
                'name' => $name,
                'arguments' => $arguments,
            ],
        ];
    }

    /**
     * @param list<object> $toolCalls
     */
    private function invokeBuildAssistantToolCallMessage(string $content, string $reasoning, array $toolCalls): array
    {
        $method = new ReflectionMethod(\Perk11\Viktor89\Assistant\OpenAiPHPClientAssistant::class, 'buildAssistantToolCallMessage');

        return $method->invoke(null, $content, $reasoning, $toolCalls);
    }

    /**
     * Reproduces the infinite image-regeneration loop from the incident log:
     * the model calls the same tool every round. Without the consecutive
     * same-tool guard the loop only stops when the fake runs out of responses
     * (and throws "No fake responses left").
     */
    public function testToolCallLoopAbortsWhenSameToolIsCalledRepeatedly(): void
    {
        $maxConsecutive = (new \ReflectionClass(OpenAiPHPClientAssistant::class))
            ->getConstant('MAX_CONSECUTIVE_SAME_TOOL_USES');

        $imageTool = new CountingToolCallExecutor();

        // Queue far more responses than the cap; if the guard fails this throws.
        $responses = [];
        for ($i = 0; $i < $maxConsecutive + 10; $i++) {
            $responses[] = $this->toolCallStreamResponse('image_gen_tool');
        }

        $assistant = $this->buildAssistantWithTools(
            ['image_gen_tool' => new ToolDefinition(
                'image_gen_tool',
                $imageTool,
                'Generate an image',
                [new ToolParameter('prompt', ['type' => 'string'], true)],
            )],
            new ClientFake($responses),
        );

        $response = $assistant->getCompletionBasedOnContext(
            $this->buildContext(),
            static fn (string $chunk) => null,
        );

        $this->assertSame($maxConsecutive, $imageTool->callCount);
        $this->assertCount($maxConsecutive, $response->toolCalls);
        $this->assertStringContainsString('Aborting the tool-call loop', $response->getDisplayContent());
        $this->assertStringContainsString('same tool', $response->getDisplayContent());
    }

    /**
     * Calling a different tool must reset the consecutive-same-tool counter, so
     * a legitimate alternating workflow (e.g. generate -> search -> generate)
     * is not aborted even though one tool is used more than the cap in total.
     */
    public function testCallingADifferentToolResetsTheConsecutiveSameToolCounter(): void
    {
        $maxConsecutive = (new \ReflectionClass(OpenAiPHPClientAssistant::class))
            ->getConstant('MAX_CONSECUTIVE_SAME_TOOL_USES');

        $imageTool = new CountingToolCallExecutor();
        $searchTool = new CountingToolCallExecutor(['status' => 'ok', 'content' => 'search results']);

        // Interleave so neither tool is ever called twice in a row, even though
        // image_gen_tool is invoked more than MAX_CONSECUTIVE_SAME_TOOL_USES times.
        $imageCalls = $maxConsecutive + 2;
        $responses = [];
        for ($i = 0; $i < $imageCalls; $i++) {
            $responses[] = $this->toolCallStreamResponse('image_gen_tool');
            $responses[] = $this->toolCallStreamResponse('web_search');
        }
        $responses[] = $this->textStreamResponse('Done');

        $assistant = $this->buildAssistantWithTools(
            [
                'image_gen_tool' => new ToolDefinition(
                    'image_gen_tool',
                    $imageTool,
                    'Generate an image',
                    [new ToolParameter('prompt', ['type' => 'string'], true)],
                ),
                'web_search' => new ToolDefinition(
                    'web_search',
                    $searchTool,
                    'Search the web',
                    [new ToolParameter('query', ['type' => 'string'], true)],
                ),
            ],
            new ClientFake($responses),
        );

        $response = $assistant->getCompletionBasedOnContext(
            $this->buildContext(),
            static fn (string $chunk) => null,
        );

        $this->assertSame($imageCalls, $imageTool->callCount);
        $this->assertSame($imageCalls, $searchTool->callCount);
        $this->assertStringNotContainsString('Aborting the tool-call loop', $response->getDisplayContent());
        $this->assertSame('Done', $response->content);
    }

    /**
     * Even when tools alternate (so the consecutive counter never trips), the
     * hard total ceiling must still stop the loop.
     */
    public function testToolCallLoopAbortsAtTotalToolUseCeilingEvenWhenAlternatingTools(): void
    {
        $maxTotal = (new \ReflectionClass(OpenAiPHPClientAssistant::class))
            ->getConstant('MAX_TOTAL_TOOL_USES');

        $imageTool = new CountingToolCallExecutor();
        $searchTool = new CountingToolCallExecutor(['status' => 'ok']);

        $responses = [];
        for ($i = 0; $i < $maxTotal; $i++) {
            $responses[] = $this->toolCallStreamResponse($i % 2 === 0 ? 'image_gen_tool' : 'web_search');
        }
        // One extra response proves we stopped because of the cap, not exhaustion.
        $responses[] = $this->toolCallStreamResponse('image_gen_tool');

        $assistant = $this->buildAssistantWithTools(
            [
                'image_gen_tool' => new ToolDefinition(
                    'image_gen_tool',
                    $imageTool,
                    'Generate an image',
                    [new ToolParameter('prompt', ['type' => 'string'], true)],
                ),
                'web_search' => new ToolDefinition(
                    'web_search',
                    $searchTool,
                    'Search the web',
                    [new ToolParameter('query', ['type' => 'string'], true)],
                ),
            ],
            new ClientFake($responses),
        );

        $response = $assistant->getCompletionBasedOnContext(
            $this->buildContext(),
            static fn (string $chunk) => null,
        );

        $this->assertSame($maxTotal, $imageTool->callCount + $searchTool->callCount);
        $this->assertStringContainsString('Aborting the tool-call loop', $response->getDisplayContent());
        $this->assertStringContainsString('total limit', $response->getDisplayContent());
    }

    /**
     * A vision-capable assistant must receive the generated image in the tool
     * result so it can see (and judge) what it just produced instead of
     * re-generating it blindly.
     */
    public function testGeneratedImageIsAddedToContextForVisionAssistant(): void
    {
        $imageTool = new ContextImageToolCallExecutor(self::tinyPng());

        $responses = [
            $this->toolCallStreamResponse('image_gen_tool'),
            $this->toolCallStreamResponse('image_gen_tool'),
            $this->textStreamResponse('Done'),
        ];
        $fake = new ClientFake($responses);

        $assistant = $this->buildAssistantWithTools(
            ['image_gen_tool' => new ToolDefinition(
                'image_gen_tool',
                $imageTool,
                'Generate an image',
                [new ToolParameter('prompt', ['type' => 'string'], true)],
            )],
            $fake,
            true,
        );
        $assistant->getCompletionBasedOnContext(
            $this->buildContext(),
            static fn (string $chunk) => null,
        );

        $fake->assertSent(\OpenAI\Resources\Chat::class, static function (string $method, array $parameters): bool {
            return $method === 'createStreamed'
                && self::toolMessagesContainImage($parameters['messages'] ?? []);
        });
    }

    /**
     * When the assistant cannot handle images, the generated image must not be
     * sent to the model (the tool result stays plain text).
     */
    /**
     * A non-vision assistant cannot receive the raw image, so the generated image
     * is described via the AltTextProvider and that description is added to the
     * tool result (no image_url part is sent).
     */
    public function testGeneratedImageIsDescribedViaAltTextForNonVisionAssistant(): void
    {
        $altTextProvider = $this->createStub(AltTextProvider::class);
        $altTextProvider->method('generateAltTextForImageString')
            ->willReturn('A silver Volkswagen Golf parked inside a garage.');

        $imageTool = new ContextImageToolCallExecutor(self::tinyPng());

        $responses = [
            $this->toolCallStreamResponse('image_gen_tool'),
            $this->toolCallStreamResponse('image_gen_tool'),
            $this->textStreamResponse('Done'),
        ];
        $fake = new ClientFake($responses);

        $assistant = $this->buildAssistantWithTools(
            ['image_gen_tool' => new ToolDefinition(
                'image_gen_tool',
                $imageTool,
                'Generate an image',
                [new ToolParameter('prompt', ['type' => 'string'], true)],
            )],
            $fake,
            false,
            $altTextProvider,
        );
        $assistant->getCompletionBasedOnContext(
            $this->buildContext(),
            static fn (string $chunk) => null,
        );

        // The raw image is never sent, but its description reaches the model.
        $fake->assertNotSent(\OpenAI\Resources\Chat::class, static function (string $method, array $parameters): bool {
            return $method === 'createStreamed'
                && self::toolMessagesContainImage($parameters['messages'] ?? []);
        });
        $fake->assertSent(\OpenAI\Resources\Chat::class, static function (string $method, array $parameters): bool {
            return $method === 'createStreamed'
                && self::toolMessagesContainText($parameters['messages'] ?? [], 'A silver Volkswagen Golf parked inside a garage.');
        });
    }

    private function buildAssistantWithTools(array $tools, ClientFake $fake, bool $supportsImages = false, ?AltTextProvider $altTextProvider = null): OpenAiPHPClientAssistant
    {
        return new OpenAiPHPClientAssistant(
            'test-model',
            $this->createStub(\Perk11\Viktor89\UserPreferenceReaderInterface::class),
            $this->createStub(\Perk11\Viktor89\UserPreferenceReaderInterface::class),
            $this->createStub(\Perk11\Viktor89\UserPreferenceReaderInterface::class),
            $this->createStub(\Perk11\Viktor89\TelegramFileDownloader::class),
            $altTextProvider ?? $this->createStub(AltTextProvider::class),
            $this->createStub(\Perk11\Viktor89\ProcessingResultExecutor::class),
            1,
            'http://localhost',
            '',
            $supportsImages,
            $tools,
            $this->createStub(\Perk11\Viktor89\Assistant\Compaction\CompactionSummaryStoreInterface::class),
            new NullLogger(),
            $fake,
        );
    }

    private function buildContext(): AssistantContext
    {
        $context = new AssistantContext();
        $context->systemPrompt = 'You are a test assistant.';
        $message = new AssistantContextMessage();
        $message->isUser = true;
        $message->text = 'generate an image';
        $context->messages[] = $message;

        return $context;
    }

    private function toolCallStreamResponse(string $toolName): \OpenAI\Responses\StreamResponse
    {
        return $this->streamResponseFromChunks([[
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion.chunk',
            'created' => 1,
            'model' => 'test-model',
            'choices' => [[
                'index' => 0,
                'delta' => [
                    'role' => 'assistant',
                    'tool_calls' => [[
                        'index' => 0,
                        'id' => 'call_' . random_int(1, PHP_INT_MAX),
                        'type' => 'function',
                        'function' => ['name' => $toolName, 'arguments' => '{"prompt":"x"}'],
                    ]],
                ],
                'finish_reason' => null,
            ]],
        ]]);
    }

    private function textStreamResponse(string $text): \OpenAI\Responses\StreamResponse
    {
        return $this->streamResponseFromChunks([[
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion.chunk',
            'created' => 1,
            'model' => 'test-model',
            'choices' => [[
                'index' => 0,
                'delta' => ['role' => 'assistant', 'content' => $text],
                'finish_reason' => 'stop',
            ]],
        ]]);
    }

    /**
     * @param list<array<string, mixed>> $chunks
     */
    private function streamResponseFromChunks(array $chunks): \OpenAI\Responses\StreamResponse
    {
        $sse = '';
        foreach ($chunks as $chunk) {
            $sse .= 'data: ' . json_encode($chunk, JSON_THROW_ON_ERROR) . "\n\n";
        }
        $sse .= "data: [DONE]\n\n";

        $resource = fopen('php://memory', 'r+');
        fwrite($resource, $sse);
        rewind($resource);

        return CreateStreamedResponse::fake($resource);
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private static function toolMessagesContainImage(array $messages): bool
    {
        foreach ($messages as $message) {
            if (($message['role'] ?? null) !== 'tool') {
                continue;
            }
            $content = $message['content'] ?? '';
            if (is_array($content)) {
                foreach ($content as $part) {
                    if (($part['type'] ?? null) === 'image_url') {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private static function toolMessagesContainText(array $messages, string $text): bool
    {
        foreach ($messages as $message) {
            if (($message['role'] ?? null) !== 'tool') {
                continue;
            }
            $content = $message['content'] ?? '';
            if (is_string($content) && str_contains($content, $text)) {
                return true;
            }
            if (is_array($content)) {
                foreach ($content as $part) {
                    if (($part['type'] ?? null) === 'text' && str_contains((string) ($part['text'] ?? ''), $text)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function tinyPng(): string
    {
        $image = imagecreatetruecolor(1, 1);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }
}

/** Tool executor that counts how often it was invoked and returns a fixed result. */
class CountingToolCallExecutor implements ToolCallExecutorInterface
{
    public int $callCount = 0;

    public function __construct(private readonly array $result = ['status' => 'image_succesfully_generated_and_sent_to_user'])
    {
    }

    public function executeToolCall(array $arguments): array
    {
        $this->callCount++;

        return $this->result;
    }
}

/** Tool executor that counts calls and returns a fixed image as context_image. */
class ContextImageToolCallExecutor implements ToolCallExecutorInterface
{
    public int $callCount = 0;

    public function __construct(private readonly string $imagePng)
    {
    }

    public function executeToolCall(array $arguments): array
    {
        $this->callCount++;

        return [
            'status' => 'image_succesfully_generated_and_sent_to_user',
            'context_image' => $this->imagePng,
        ];
    }
}
