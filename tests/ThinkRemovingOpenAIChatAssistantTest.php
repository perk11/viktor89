<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\Assistant\ThinkRemovingOpenAIChatAssistant::class)]
class ThinkRemovingOpenAIChatAssistantTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\ThinkRemovingOpenAIChatAssistant::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testExtendsOpenAiChatAssistant(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\ThinkRemovingOpenAIChatAssistant::class);
        $parent = $reflection->getParentClass();
        $this->assertNotNull($parent);
        $this->assertSame(\Perk11\Viktor89\Assistant\OpenAiChatAssistant::class, $parent->getName());
    }

    public function testOverridesGetCompletionBasedOnContext(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\ThinkRemovingOpenAIChatAssistant::class);
        $method = $reflection->getMethod('getCompletionBasedOnContext');
        $this->assertSame(
            \Perk11\Viktor89\Assistant\ThinkRemovingOpenAIChatAssistant::class,
            $method->getDeclaringClass()->getName()
        );
    }

    public function testGetCompletionReturnsCompletionResponse(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\ThinkRemovingOpenAIChatAssistant::class);
        $method = $reflection->getMethod('getCompletionBasedOnContext');
        $returnType = $method->getReturnType();
        $this->assertSame(\Perk11\Viktor89\Assistant\CompletionResponse::class, $returnType->getName());
    }
}
