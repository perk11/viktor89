<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\ImageGeneration\SendAsDocumentProcessor::class)]
class SendAsDocumentProcessorTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\ImageGeneration\SendAsDocumentProcessor::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testImplementsMessageChainProcessor(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\ImageGeneration\SendAsDocumentProcessor::class);
        $this->assertTrue(
            $reflection->implementsInterface(\Perk11\Viktor89\MessageChainProcessor::class)
        );
    }

    public function testHasProcessMessageChainMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\ImageGeneration\SendAsDocumentProcessor::class);
        $method = $reflection->getMethod('processMessageChain');
        $this->assertFalse($method->isAbstract());
    }

    public function testConstructorTakesTwoParameters(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\ImageGeneration\SendAsDocumentProcessor::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame(\Perk11\Viktor89\CacheFileManager::class, $params[0]->getType()->getName());
        $this->assertSame(\Perk11\Viktor89\Repository\MessageRepository::class, $params[1]->getType()->getName());
    }

    public function testHasPrivateGenerateRandomStringMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\ImageGeneration\SendAsDocumentProcessor::class);
        $method = $reflection->getMethod('generateRandomString');
        $this->assertTrue($method->isPrivate());
        $this->assertSame('string', $method->getReturnType()->getName());
    }
}
