<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\Assistant\AltTextProvider::class)]
class AltTextProviderTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\AltTextProvider::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testHasProvideMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\AltTextProvider::class);
        $method = $reflection->getMethod('provide');
        $params = $method->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('internalMessage', $params[0]->getName());
        $this->assertSame(\Perk11\Viktor89\InternalMessage::class, $params[0]->getType()->getName());
        $this->assertSame('progressUpdateCallback', $params[1]->getName());
    }

    public function testProvideReturnsNullableString(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\AltTextProvider::class);
        $method = $reflection->getMethod('provide');
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertTrue($returnType->allowsNull());
    }

    public function testHasAssistantWithVisionProperty(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\AltTextProvider::class);
        $property = $reflection->getProperty('assistantWithVision');
        $this->assertSame(\Perk11\Viktor89\Assistant\AssistantInterface::class, $property->getType()->getName());
    }

    public function testConstructorTakesThreeParameters(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\AltTextProvider::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();
        $this->assertCount(4, $params);
        $this->assertSame(\Perk11\Viktor89\TelegramFileDownloader::class, $params[0]->getType()->getName());
        $this->assertSame(\Perk11\Viktor89\VoiceRecognition\InternalMessageTranscriber::class, $params[1]->getType()->getName());
        $this->assertSame(\Perk11\Viktor89\Repository\MessageRepository::class, $params[2]->getType()->getName());
    }
}
