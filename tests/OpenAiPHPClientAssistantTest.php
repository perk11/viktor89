<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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
}
