<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\PreResponseProcessor\WhoAreYouProcessor::class)]
class WhoAreYouProcessorTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PreResponseProcessor\WhoAreYouProcessor::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testImplementsMessageChainProcessor(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PreResponseProcessor\WhoAreYouProcessor::class);
        $this->assertTrue(
            $reflection->implementsInterface(\Perk11\Viktor89\MessageChainProcessor::class)
        );
    }

    public function testHasProcessMessageChainMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PreResponseProcessor\WhoAreYouProcessor::class);
        $method = $reflection->getMethod('processMessageChain');
        $this->assertFalse($method->isAbstract());
    }

    public function testHasNoCustomConstructor(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PreResponseProcessor\WhoAreYouProcessor::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);
    }

    public function testHasViktor89StickersProperty(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PreResponseProcessor\WhoAreYouProcessor::class);
        $property = $reflection->getProperty('viktor89Stickers');
        $this->assertTrue($property->isPrivate());
    }

    public function testHasMultipleStickers(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\PreResponseProcessor\WhoAreYouProcessor::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $property = $reflection->getProperty('viktor89Stickers');
        $stickers = $property->getValue($instance);
        $this->assertGreaterThanOrEqual(2, count($stickers));
    }
}
