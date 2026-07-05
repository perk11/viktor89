<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\VoiceGeneration\VoiceResponder::class)]
class VoiceResponderTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\VoiceGeneration\VoiceResponder::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testHasSendVoiceMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\VoiceGeneration\VoiceResponder::class);
        $method = $reflection->getMethod('sendVoice');
        $params = $method->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('messageToReplyTo', $params[0]->getName());
        $this->assertSame(\Perk11\Viktor89\InternalMessage::class, $params[0]->getType()->getName());
        $this->assertSame('voiceOggContents', $params[1]->getName());
        $this->assertSame('string', $params[1]->getType()->getName());
    }

    public function testSendVoiceReturnsVoid(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\VoiceGeneration\VoiceResponder::class);
        $method = $reflection->getMethod('sendVoice');
        $this->assertSame('void', $method->getReturnType()->getName());
    }

    public function testHasNoCustomConstructor(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\VoiceGeneration\VoiceResponder::class);
        $constructor = $reflection->getConstructor();
        $this->assertNull($constructor);
    }
}
