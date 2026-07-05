<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AssistantInterface;
use Perk11\Viktor89\Assistant\ContextCompletingAssistantInterface;
use Perk11\Viktor89\MessageChainProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AssistantInterface::class)]
class AssistantInterfaceTest extends TestCase
{
    public function testIsInterface(): void
    {
        $reflection = new \ReflectionClass(AssistantInterface::class);
        $this->assertTrue($reflection->isInterface());
    }

    public function testExtendsMessageChainProcessor(): void
    {
        $reflection = new \ReflectionClass(AssistantInterface::class);
        $parents = $reflection->getInterfaces();

        $this->assertArrayHasKey(MessageChainProcessor::class, $parents);
    }

    public function testExtendsContextCompletingAssistantInterface(): void
    {
        $reflection = new \ReflectionClass(AssistantInterface::class);
        $parents = $reflection->getInterfaces();

        $this->assertArrayHasKey(ContextCompletingAssistantInterface::class, $parents);
    }

    public function testHasNoOwnMethods(): void
    {
        $reflection = new \ReflectionClass(AssistantInterface::class);

        $ownMethods = array_filter(
            $reflection->getMethods(),
            fn($m) => $m->getDeclaringClass()->getName() === AssistantInterface::class
        );

        $this->assertCount(0, $ownMethods);
    }
}
