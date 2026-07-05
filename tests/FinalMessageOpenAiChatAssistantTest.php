<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\Assistant\FinalMessageOpenAiChatAssistant::class)]
class FinalMessageOpenAiChatAssistantTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\FinalMessageOpenAiChatAssistant::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testImplementsAssistantInterface(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\FinalMessageOpenAiChatAssistant::class);
        $this->assertTrue(
            $reflection->implementsInterface(\Perk11\Viktor89\Assistant\AssistantInterface::class)
        );
    }

    public function testHasGetCompletionBasedOnContextMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\FinalMessageOpenAiChatAssistant::class);
        $method = $reflection->getMethod('getCompletionBasedOnContext');
        $this->assertFalse($method->isAbstract());
    }

    public function testHasProcessMessageChainMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\FinalMessageOpenAiChatAssistant::class);
        $method = $reflection->getMethod('processMessageChain');
        $this->assertFalse($method->isAbstract());
    }
}
