<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\IPC\ChannelMessage;
use Perk11\Viktor89\IPC\TaskCompletedMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaskCompletedMessage::class)]
class TaskCompletedMessageTest extends TestCase
{
    public function testConstructorStoresWorkerId(): void
    {
        $msg = new TaskCompletedMessage(42);

        $this->assertSame(42, $msg->workerId);
    }

    public function testExtendsChannelMessage(): void
    {
        $msg = new TaskCompletedMessage(1);

        $this->assertInstanceOf(ChannelMessage::class, $msg);
    }

    public function testConstructorWithZeroWorkerId(): void
    {
        $msg = new TaskCompletedMessage(0);

        $this->assertSame(0, $msg->workerId);
    }

    public function testConstructorWithLargeWorkerId(): void
    {
        $msg = new TaskCompletedMessage(999999);

        $this->assertSame(999999, $msg->workerId);
    }

    public function testWorkerIdIsReadonly(): void
    {
        $msg = new TaskCompletedMessage(1);

        $this->expectException(\Error::class);

        $msg->workerId = 2;
    }
}
