<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\Tool\MessageChainAwareToolCallExecutorInterface;
use Perk11\Viktor89\MessageChain;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageChainAwareToolCallExecutorInterface::class)]
class MessageChainAwareToolCallExecutorInterfaceTest extends TestCase
{
    public function testIsInterface(): void
    {
        $reflection = new \ReflectionClass(MessageChainAwareToolCallExecutorInterface::class);
        $this->assertTrue($reflection->isInterface());
    }

    public function testHasExecuteToolCallMethod(): void
    {
        $reflection = new \ReflectionClass(MessageChainAwareToolCallExecutorInterface::class);
        $method = $reflection->getMethod('executeToolCall');
        $this->assertTrue($method->isAbstract());
    }

    public function testMethodTakesArrayAndMessageChain(): void
    {
        $reflection = new \ReflectionClass(MessageChainAwareToolCallExecutorInterface::class);
        $method = $reflection->getMethod('executeToolCall');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertSame('arguments', $params[0]->getName());
        $this->assertSame('array', $params[0]->getType()->getName());
        $this->assertSame('messageChain', $params[1]->getName());
        $this->assertSame(MessageChain::class, $params[1]->getType()->getName());
    }

    public function testMethodReturnsArray(): void
    {
        $reflection = new \ReflectionClass(MessageChainAwareToolCallExecutorInterface::class);
        $method = $reflection->getMethod('executeToolCall');
        $returnType = $method->getReturnType();

        $this->assertSame('array', $returnType->getName());
    }
}
