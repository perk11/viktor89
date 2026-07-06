<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\IPC\AckMessage;
use Perk11\Viktor89\IPC\ChannelMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AckMessage::class)]
class AckMessageTest extends TestCase
{
    public function testExtendsChannelMessage(): void
    {
        $this->assertInstanceOf(ChannelMessage::class, new AckMessage());
    }
}
