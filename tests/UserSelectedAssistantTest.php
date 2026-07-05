<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\Assistant\UserSelectedAssistant::class)]
class UserSelectedAssistantTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\UserSelectedAssistant::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testImplementsMessageChainProcessor(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\UserSelectedAssistant::class);
        $this->assertTrue(
            $reflection->implementsInterface(\Perk11\Viktor89\MessageChainProcessor::class)
        );
    }

    public function testHasProcessMessageChainMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\UserSelectedAssistant::class);
        $method = $reflection->getMethod('processMessageChain');
        $this->assertFalse($method->isAbstract());
    }

    public function testConstructorTakesAssistantFactoryAndPreference(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\UserSelectedAssistant::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('assistantFactory', $params[0]->getName());
        $this->assertSame(\Perk11\Viktor89\Assistant\AssistantFactory::class, $params[0]->getType()->getName());
        $this->assertSame(\Perk11\Viktor89\UserPreferenceReaderInterface::class, $params[1]->getType()->getName());
    }
}
