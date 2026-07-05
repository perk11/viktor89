<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\PreResponseProcessor\RateLimitProcessor::class)]
class RateLimitProcessorTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PreResponseProcessor\RateLimitProcessor::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testImplementsPreResponseProcessor(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PreResponseProcessor\RateLimitProcessor::class);
        $interfaces = $reflection->getInterfaceNames();
        $this->assertContains(\Perk11\Viktor89\PreResponseProcessor\PreResponseProcessor::class, $interfaces);
    }

    public function testHasProcessMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PreResponseProcessor\RateLimitProcessor::class);
        $method = $reflection->getMethod('process');
        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('message', $params[0]->getName());
        $this->assertSame(\Longman\TelegramBot\Entities\Message::class, $params[0]->getType()->getName());
    }

    public function testProcessReturnsFalseStringOrNullable(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PreResponseProcessor\RateLimitProcessor::class);
        $method = $reflection->getMethod('process');
        $returnType = $method->getReturnType();
        $types = $returnType->getTypes();
        $this->assertCount(3, $types);
    }

    public function testConstructorTakesDatabaseAndBotUserIdAndConfig(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PreResponseProcessor\RateLimitProcessor::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();
        $this->assertCount(3, $params);
        $this->assertSame('database', $params[0]->getName());
        $this->assertSame(\Perk11\Viktor89\Database::class, $params[0]->getType()->getName());
    }
}
