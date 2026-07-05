<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\IPC\ChannelMessage;
use Perk11\Viktor89\IPC\RunningTask;
use Perk11\Viktor89\IPC\RunningTasksReportMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunningTasksReportMessage::class)]
class RunningTasksReportMessageTest extends TestCase
{
    public function testConstructorWithEmptyTasks(): void
    {
        $msg = new RunningTasksReportMessage([]);

        $this->assertSame([], $msg->runningTasks);
    }

    public function testConstructorWithTasks(): void
    {
        $task = new RunningTask('Processor', 'status', new \DateTimeImmutable());
        $msg = new RunningTasksReportMessage([$task]);

        $this->assertCount(1, $msg->runningTasks);
        $this->assertSame($task, $msg->runningTasks[0]);
    }

    public function testExtendsChannelMessage(): void
    {
        $msg = new RunningTasksReportMessage([]);

        $this->assertInstanceOf(ChannelMessage::class, $msg);
    }

    public function testConstructorWithMultipleTasks(): void
    {
        $tasks = [
            new RunningTask('P1', 's1', new \DateTimeImmutable()),
            new RunningTask('P2', 's2', new \DateTimeImmutable()),
            new RunningTask('P3', 's3', new \DateTimeImmutable()),
        ];
        $msg = new RunningTasksReportMessage($tasks);

        $this->assertCount(3, $msg->runningTasks);
    }

    public function testRunningTasksIsReadonly(): void
    {
        $msg = new RunningTasksReportMessage([]);

        $this->expectException(\Error::class);

        $msg->runningTasks = [];
    }
}
