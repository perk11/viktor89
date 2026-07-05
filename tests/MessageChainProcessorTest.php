<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageChainProcessor::class)]
class MessageChainProcessorTest extends TestCase
{
    public function testIsInterface(): void
    {
        $reflection = new \ReflectionClass(MessageChainProcessor::class);
        $this->assertTrue($reflection->isInterface());
    }

    public function testHasProcessMessageChainMethod(): void
    {
        $reflection = new \ReflectionClass(MessageChainProcessor::class);
        $method = $reflection->getMethod('processMessageChain');
        $this->assertTrue($method->isAbstract());
    }

    public function testProcessMessageChainParameters(): void
    {
        $reflection = new \ReflectionClass(MessageChainProcessor::class);
        $method = $reflection->getMethod('processMessageChain');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertSame('messageChain', $params[0]->getName());
        $this->assertSame(MessageChain::class, $params[0]->getType()->getName());
        $this->assertSame('progressUpdateCallback', $params[1]->getName());
        $this->assertSame(ProgressUpdateCallback::class, $params[1]->getType()->getName());
    }

    public function testProcessMessageChainReturnsProcessingResult(): void
    {
        $reflection = new \ReflectionClass(MessageChainProcessor::class);
        $method = $reflection->getMethod('processMessageChain');
        $returnType = $method->getReturnType();

        $this->assertSame(ProcessingResult::class, $returnType->getName());
    }
}
