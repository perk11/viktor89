<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\ContextCompletingAssistantInterface;
use Perk11\Viktor89\Assistant\CompletionResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContextCompletingAssistantInterface::class)]
class ContextCompletingAssistantInterfaceTest extends TestCase
{
    public function testIsInterface(): void
    {
        $reflection = new \ReflectionClass(ContextCompletingAssistantInterface::class);
        $this->assertTrue($reflection->isInterface());
    }

    public function testHasGetCompletionBasedOnContextMethod(): void
    {
        $reflection = new \ReflectionClass(ContextCompletingAssistantInterface::class);
        $method = $reflection->getMethod('getCompletionBasedOnContext');
        $this->assertTrue($method->isAbstract());
    }

    public function testMethodTakesAssistantContext(): void
    {
        $reflection = new \ReflectionClass(ContextCompletingAssistantInterface::class);
        $method = $reflection->getMethod('getCompletionBasedOnContext');
        $params = $method->getParameters();

        $this->assertGreaterThanOrEqual(1, count($params));
        $this->assertSame('assistantContext', $params[0]->getName());
        $this->assertSame(AssistantContext::class, $params[0]->getType()->getName());
    }

    public function testMethodReturnsCompletionResponse(): void
    {
        $reflection = new \ReflectionClass(ContextCompletingAssistantInterface::class);
        $method = $reflection->getMethod('getCompletionBasedOnContext');
        $returnType = $method->getReturnType();

        $this->assertSame(CompletionResponse::class, $returnType->getName());
    }
}
