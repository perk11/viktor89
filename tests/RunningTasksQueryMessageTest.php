<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\IPC\ChannelMessage;
use Perk11\Viktor89\IPC\RunningTasksQueryMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunningTasksQueryMessage::class)]
class RunningTasksQueryMessageTest extends TestCase
{
    public function testExtendsChannelMessage(): void
    {
        $msg = new RunningTasksQueryMessage();

        $this->assertInstanceOf(ChannelMessage::class, $msg);
    }

    public function testHasNoProperties(): void
    {
        $msg = new RunningTasksQueryMessage();
        $reflection = new \ReflectionClass($msg);

        // RunningTasksQueryMessage has no own properties
        $this->assertSame(0, count(array_filter(
            $reflection->getProperties(),
            fn($p) => !$p->isStatic() && $p->getDeclaringClass()->getName() === RunningTasksQueryMessage::class
        )));
    }

    public function testCanInstantiate(): void
    {
        $msg = new RunningTasksQueryMessage();
        $this->assertIsObject($msg);
    }

    public function testMultipleInstancesAreIndependent(): void
    {
        $msg1 = new RunningTasksQueryMessage();
        $msg2 = new RunningTasksQueryMessage();

        $this->assertNotSame($msg1, $msg2);
    }
}
