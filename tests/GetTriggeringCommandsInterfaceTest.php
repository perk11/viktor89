<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\GetTriggeringCommandsInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetTriggeringCommandsInterface::class)]
class GetTriggeringCommandsInterfaceTest extends TestCase
{
    public function testIsInterface(): void
    {
        $reflection = new \ReflectionClass(GetTriggeringCommandsInterface::class);
        $this->assertTrue($reflection->isInterface());
    }

    public function testHasGetTriggeringCommandsMethod(): void
    {
        $reflection = new \ReflectionClass(GetTriggeringCommandsInterface::class);
        $method = $reflection->getMethod('getTriggeringCommands');
        $this->assertTrue($method->isAbstract());
    }

    public function testMethodReturnsArray(): void
    {
        $reflection = new \ReflectionClass(GetTriggeringCommandsInterface::class);
        $method = $reflection->getMethod('getTriggeringCommands');
        $returnType = $method->getReturnType();

        $this->assertSame('array', $returnType->getName());
    }

    public function testMethodHasNoParameters(): void
    {
        $reflection = new \ReflectionClass(GetTriggeringCommandsInterface::class);
        $method = $reflection->getMethod('getTriggeringCommands');

        $this->assertCount(0, $method->getParameters());
    }
}
