<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\IPC\ChannelMessage;
use Perk11\Viktor89\IPC\DraftState;
use Perk11\Viktor89\IPC\DraftUpdateMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DraftUpdateMessage::class)]
class DraftUpdateMessageTest extends TestCase
{
    public function testConstructorStoresValues(): void
    {
        $draft = new DraftState(-100, 9, 'Hello', 'RichMarkdown');
        $msg = new DraftUpdateMessage(3, $draft);

        $this->assertSame(3, $msg->workerId);
        $this->assertSame($draft, $msg->draft);
    }

    public function testExtendsChannelMessage(): void
    {
        $msg = new DraftUpdateMessage(1, new DraftState(1, 1, 't', 'Default'));

        $this->assertInstanceOf(ChannelMessage::class, $msg);
    }
}
