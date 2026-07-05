<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\ImageGeneration\DownscaleProcessor::class)]
class DownscaleProcessorTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\ImageGeneration\DownscaleProcessor::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testImplementsMessageChainProcessor(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\ImageGeneration\DownscaleProcessor::class);
        $this->assertTrue(
            $reflection->implementsInterface(\Perk11\Viktor89\MessageChainProcessor::class)
        );
    }

    public function testHasProcessMessageChainMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\ImageGeneration\DownscaleProcessor::class);
        $method = $reflection->getMethod('processMessageChain');
        $this->assertFalse($method->isAbstract());
    }

    public function testConstructorTakesTwoParameters(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\ImageGeneration\DownscaleProcessor::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame(\Perk11\Viktor89\TelegramFileDownloader::class, $params[0]->getType()->getName());
        $this->assertSame(\Perk11\Viktor89\ImageGeneration\PhotoResponder::class, $params[1]->getType()->getName());
    }
}
