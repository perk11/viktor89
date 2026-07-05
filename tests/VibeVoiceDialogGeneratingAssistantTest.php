<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\Assistant\VibeVoiceDialogGeneratingAssistant::class)]
class VibeVoiceDialogGeneratingAssistantTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\VibeVoiceDialogGeneratingAssistant::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testHasGetCompletionBasedOnContextMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\VibeVoiceDialogGeneratingAssistant::class);
        $method = $reflection->getMethod('getCompletionBasedOnContext');
        $this->assertFalse($method->isAbstract());
    }

    public function testHasConstructorWithVoiceResponder(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Assistant\VibeVoiceDialogGeneratingAssistant::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);
    }
}
