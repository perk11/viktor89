<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\TelegramInternalMessageResponderInterface::class)]
class TelegramInternalMessageResponderInterfaceTest extends TestCase
{
    public function testIsInterface(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\TelegramInternalMessageResponderInterface::class);
        $this->assertTrue($reflection->isInterface());
    }

    public function testHasGetResponseByMessageMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\TelegramInternalMessageResponderInterface::class);
        $method = $reflection->getMethod('getResponseByMessage');
        $this->assertTrue($method->isAbstract());
    }

    public function testMethodTakesMessageAndProgressUpdateCallback(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\TelegramInternalMessageResponderInterface::class);
        $method = $reflection->getMethod('getResponseByMessage');
        $params = $method->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('message', $params[0]->getName());
        $this->assertSame(\Longman\TelegramBot\Entities\Message::class, $params[0]->getType()->getName());
        $this->assertSame('progressUpdateCallback', $params[1]->getName());
        $this->assertSame(\Perk11\Viktor89\IPC\ProgressUpdateCallback::class, $params[1]->getType()->getName());
    }

    public function testMethodReturnsNullableInternalMessage(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\TelegramInternalMessageResponderInterface::class);
        $method = $reflection->getMethod('getResponseByMessage');
        $returnType = $method->getReturnType();
        $this->assertTrue($returnType->allowsNull());
        $this->assertSame(\Perk11\Viktor89\InternalMessage::class, $returnType->getName());
    }

    public function testIsDeprecated(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\TelegramInternalMessageResponderInterface::class);
        $docComment = $reflection->getDocComment();
        $this->assertStringContainsString('@deprecated', $docComment);
    }
}
