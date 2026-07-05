<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProgressUpdateCallback::class)]
class ProgressUpdateCallbackTest extends TestCase
{
    public function testIsInterface(): void
    {
        $reflection = new \ReflectionClass(ProgressUpdateCallback::class);
        $this->assertTrue($reflection->isInterface());
    }

    public function testHasInvokeMethod(): void
    {
        $reflection = new \ReflectionClass(ProgressUpdateCallback::class);
        $method = $reflection->getMethod('__invoke');
        $this->assertTrue($method->isAbstract());
    }

    public function testInvokeMethodParameters(): void
    {
        $reflection = new \ReflectionClass(ProgressUpdateCallback::class);
        $method = $reflection->getMethod('__invoke');
        $params = $method->getParameters();

        $this->assertCount(3, $params);
        $this->assertSame('processor', $params[0]->getName());
        $this->assertSame('string', $params[0]->getType()->getName());
        $this->assertSame('status', $params[1]->getName());
        $this->assertSame('string', $params[1]->getType()->getName());
        $this->assertTrue($params[2]->isOptional());
    }

    public function testHasSubscribeMethod(): void
    {
        $reflection = new \ReflectionClass(ProgressUpdateCallback::class);
        $method = $reflection->getMethod('subscribe');
        $this->assertTrue($method->isAbstract());
    }

    public function testSubscribeTakesCallableParameter(): void
    {
        $reflection = new \ReflectionClass(ProgressUpdateCallback::class);
        $method = $reflection->getMethod('subscribe');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('subscriber', $params[0]->getName());
        $this->assertSame('callable', $params[0]->getType()->getName());
    }
}
