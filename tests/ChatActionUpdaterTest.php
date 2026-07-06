<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\IPC\ChatActionUpdater::class)]
class ChatActionUpdaterTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\IPC\ChatActionUpdater::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testConstructorTakesFinalMessageTracker(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\IPC\ChatActionUpdater::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);
        $params = $constructor->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('finalMessageTracker', $params[0]->getName());
        $this->assertSame(\Perk11\Viktor89\IPC\FinalMessageTracker::class, $params[0]->getType()->getName());
        $this->assertSame('actionIntervalSeconds', $params[1]->getName());
        $this->assertSame(4.0, $params[1]->getDefaultValue());
    }

    public function testHasUpdateActionMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\IPC\ChatActionUpdater::class);
        $method = $reflection->getMethod('updateAction');
        $params = $method->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('workerIdentifier', $params[0]->getName());
        $this->assertSame('int', $params[0]->getType()->getName());
        $this->assertSame('chatActionToUpdate', $params[1]->getName());
    }

    public function testUpdateActionReturnsVoid(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\IPC\ChatActionUpdater::class);
        $method = $reflection->getMethod('updateAction');
        $this->assertSame('void', $method->getReturnType()->getName());
    }

    public function testHasRemoveActionMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\IPC\ChatActionUpdater::class);
        $method = $reflection->getMethod('removeAction');
        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('workerIdentifier', $params[0]->getName());
        $this->assertSame('int', $params[0]->getType()->getName());
    }

    public function testHasPrivateStartChatActionTimerMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\IPC\ChatActionUpdater::class);
        $method = $reflection->getMethod('startChatActionTimer');
        $this->assertTrue($method->isPrivate());
    }

    public function testHasPrivateStopChatActionTimerMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\IPC\ChatActionUpdater::class);
        $method = $reflection->getMethod('stopChatActionTimer');
        $this->assertTrue($method->isPrivate());
    }

    public function testHasPrivateChatHasPendingActionsMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\IPC\ChatActionUpdater::class);
        $method = $reflection->getMethod('chatHasPendingActions');
        $this->assertTrue($method->isPrivate());
        $this->assertSame('bool', $method->getReturnType()->getName());
    }
}
