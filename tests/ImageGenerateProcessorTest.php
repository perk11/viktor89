<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\PreResponseProcessor\ImageGenerateProcessor::class)]
class ImageGenerateProcessorTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PreResponseProcessor\ImageGenerateProcessor::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testImplementsMessageChainProcessor(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PreResponseProcessor\ImageGenerateProcessor::class);
        $this->assertTrue(
            $reflection->implementsInterface(\Perk11\Viktor89\MessageChainProcessor::class)
        );
    }

    public function testImplementsGetTriggeringCommandsInterface(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PreResponseProcessor\ImageGenerateProcessor::class);
        $this->assertTrue(
            $reflection->implementsInterface(\Perk11\Viktor89\GetTriggeringCommandsInterface::class)
        );
    }

    public function testHasGetTriggeringCommandsMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PreResponseProcessor\ImageGenerateProcessor::class);
        $method = $reflection->getMethod('getTriggeringCommands');
        $this->assertSame('array', $method->getReturnType()->getName());
    }

    public function testHasProcessMessageChainMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PreResponseProcessor\ImageGenerateProcessor::class);
        $method = $reflection->getMethod('processMessageChain');
        $this->assertFalse($method->isAbstract());
    }

    public function testHasConstructorWithManyParameters(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PreResponseProcessor\ImageGenerateProcessor::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();
        $this->assertGreaterThanOrEqual(5, count($params));
    }
}
