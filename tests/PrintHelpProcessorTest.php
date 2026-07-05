<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\PrintHelpProcessor::class)]
class PrintHelpProcessorTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PrintHelpProcessor::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testImplementsMessageChainProcessor(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PrintHelpProcessor::class);
        $this->assertTrue(
            $reflection->implementsInterface(\Perk11\Viktor89\MessageChainProcessor::class)
        );
    }

    public function testHasProcessMessageChainMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PrintHelpProcessor::class);
        $method = $reflection->getMethod('processMessageChain');
        $this->assertFalse($method->isAbstract());
    }

    public function testHasCommandsConstant(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PrintHelpProcessor::class);
        $constant = $reflection->getConstant('COMMANDS');
        $this->assertIsArray($constant);
        $this->assertArrayHasKey('/image', $constant);
    }

    public function testConstructorTakesNoParameters(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PrintHelpProcessor::class);
        $constructor = $reflection->getConstructor();
        $this->assertNull($constructor);
    }
}
