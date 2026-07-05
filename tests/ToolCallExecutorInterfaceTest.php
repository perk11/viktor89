<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\Tool\ToolCallExecutorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolCallExecutorInterface::class)]
class ToolCallExecutorInterfaceTest extends TestCase
{
    public function testIsInterface(): void
    {
        $reflection = new \ReflectionClass(ToolCallExecutorInterface::class);
        $this->assertTrue($reflection->isInterface());
    }

    public function testHasExecuteToolCallMethod(): void
    {
        $reflection = new \ReflectionClass(ToolCallExecutorInterface::class);
        $method = $reflection->getMethod('executeToolCall');
        $this->assertTrue($method->isAbstract());
    }

    public function testExecuteToolCallParameters(): void
    {
        $reflection = new \ReflectionClass(ToolCallExecutorInterface::class);
        $method = $reflection->getMethod('executeToolCall');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('arguments', $params[0]->getName());
        $this->assertSame('array', $params[0]->getType()->getName());
    }

    public function testExecuteToolCallReturnsArray(): void
    {
        $reflection = new \ReflectionClass(ToolCallExecutorInterface::class);
        $method = $reflection->getMethod('executeToolCall');
        $returnType = $method->getReturnType();

        $this->assertSame('array', $returnType->getName());
    }
}
