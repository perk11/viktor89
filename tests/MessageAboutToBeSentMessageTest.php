<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\IPC\ChannelMessage;
use Perk11\Viktor89\IPC\MessageAboutToBeSentMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageAboutToBeSentMessage::class)]
class MessageAboutToBeSentMessageTest extends TestCase
{
    public function testConstructorStoresValues(): void
    {
        $msg = new MessageAboutToBeSentMessage(3, -100123);

        $this->assertSame(3, $msg->workerId);
        $this->assertSame(-100123, $msg->chatId);
    }

    public function testExtendsChannelMessage(): void
    {
        $msg = new MessageAboutToBeSentMessage(1, 1);

        $this->assertInstanceOf(ChannelMessage::class, $msg);
    }
}
