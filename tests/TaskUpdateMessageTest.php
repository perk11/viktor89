<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\IPC\ChannelMessage;
use Perk11\Viktor89\IPC\TaskUpdateMessage;
use Perk11\Viktor89\Util\Telegram\ChatAction;
use Perk11\Viktor89\Util\Telegram\ChatActionEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaskUpdateMessage::class)]
class TaskUpdateMessageTest extends TestCase
{
    public function testConstructorStoresAllFields(): void
    {
        $chatAction = new ChatAction(-100123, ChatActionEnum::typing);
        $msg = new TaskUpdateMessage(5, 'ImageProcessor', 'Generating...', $chatAction);

        $this->assertSame(5, $msg->workerId);
        $this->assertSame('ImageProcessor', $msg->processor);
        $this->assertSame('Generating...', $msg->status);
        $this->assertSame($chatAction, $msg->chatAction);
    }

    public function testConstructorWithoutChatAction(): void
    {
        $msg = new TaskUpdateMessage(3, 'TextProcessor', 'Writing...');

        $this->assertNull($msg->chatAction);
    }

    public function testExtendsChannelMessage(): void
    {
        $msg = new TaskUpdateMessage(1, 'proc', 'status');

        $this->assertInstanceOf(ChannelMessage::class, $msg);
    }

    public function testConstructorWithDifferentChatActions(): void
    {
        $actions = [
            new ChatAction(-100, ChatActionEnum::upload_photo),
            new ChatAction(-200, ChatActionEnum::record_voice),
            new ChatAction(-300, ChatActionEnum::upload_document),
        ];

        foreach ($actions as $action) {
            $msg = new TaskUpdateMessage(1, 'processor', 'status', $action);
            $this->assertSame($action, $msg->chatAction);
        }
    }

    public function testAllPropertiesAreReadonly(): void
    {
        $msg = new TaskUpdateMessage(1, 'proc', 'status');

        $this->expectException(\Error::class);

        $msg->workerId = 2;
    }
}
