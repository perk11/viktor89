<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\IPC\RunningTaskTracker::class)]
class RunningTaskTrackerTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\IPC\RunningTaskTracker::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testHasReceiveMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\IPC\RunningTaskTracker::class);
        $method = $reflection->getMethod('receive');
        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('execution', $params[0]->getName());
        $this->assertSame(\Amp\Parallel\Worker\Execution::class, $params[0]->getType()->getName());
    }

    public function testReceiveReturnsVoid(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\IPC\RunningTaskTracker::class);
        $method = $reflection->getMethod('receive');
        $this->assertSame('void', $method->getReturnType()->getName());
    }

    public function testConstructorTakesChatActionUpdater(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\IPC\RunningTaskTracker::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame(\Perk11\Viktor89\IPC\ChatActionUpdater::class, $params[0]->getType()->getName());
    }

    public function testHasRunningTasksProperty(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\IPC\RunningTaskTracker::class);
        $property = $reflection->getProperty('runningTasks');
        $this->assertTrue($property->isPrivate());
    }
}
