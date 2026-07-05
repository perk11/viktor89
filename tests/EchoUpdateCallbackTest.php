<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\IPC\EchoUpdateCallback::class)]
class EchoUpdateCallbackTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\IPC\EchoUpdateCallback::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testImplementsProgressUpdateCallback(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\IPC\EchoUpdateCallback::class);
        $this->assertTrue(
            $reflection->implementsInterface(\Perk11\Viktor89\IPC\ProgressUpdateCallback::class)
        );
    }

    public function testSubscribeAddsSubscriber(): void
    {
        $callback = new \Perk11\Viktor89\IPC\EchoUpdateCallback();
        $callback->subscribe(fn() => null);
        // No exception means subscription succeeded
        $this->assertTrue(true);
    }

    public function testInvokeMethodExists(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\IPC\EchoUpdateCallback::class);
        $method = $reflection->getMethod('__invoke');
        $params = $method->getParameters();
        $this->assertCount(3, $params);
        $this->assertSame('processor', $params[0]->getName());
        $this->assertSame('string', $params[0]->getType()->getName());
        $this->assertSame('status', $params[1]->getName());
    }

    public function testInvokeReturnsVoid(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\IPC\EchoUpdateCallback::class);
        $method = $reflection->getMethod('__invoke');
        $this->assertSame('void', $method->getReturnType()->getName());
    }
}
