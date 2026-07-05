<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\IPC\ChannelMessage::class)]
class ChannelMessageTest extends TestCase
{
    public function testIsAbstractClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\IPC\ChannelMessage::class);
        $this->assertTrue($reflection->isAbstract());
        $this->assertFalse($reflection->isInterface());
    }

    public function testIsInIpcNamespace(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\IPC\ChannelMessage::class);
        $this->assertSame('Perk11\Viktor89\IPC', $reflection->getNamespaceName());
    }
}
