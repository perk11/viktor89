<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Longman\TelegramBot\Entities\Message;
use Perk11\Viktor89\TelegramResponderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TelegramResponderInterface::class)]
class TelegramResponderInterfaceTest extends TestCase
{
    public function testIsInterface(): void
    {
        $reflection = new \ReflectionClass(TelegramResponderInterface::class);
        $this->assertTrue($reflection->isInterface());
    }

    public function testHasGetResponseByMessageMethod(): void
    {
        $reflection = new \ReflectionClass(TelegramResponderInterface::class);
        $method = $reflection->getMethod('getResponseByMessage');
        $this->assertTrue($method->isAbstract());
    }

    public function testMethodTakesMessageParameter(): void
    {
        $reflection = new \ReflectionClass(TelegramResponderInterface::class);
        $method = $reflection->getMethod('getResponseByMessage');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('message', $params[0]->getName());
        $this->assertSame(Message::class, $params[0]->getType()->getName());
    }

    public function testMethodReturnsString(): void
    {
        $reflection = new \ReflectionClass(TelegramResponderInterface::class);
        $method = $reflection->getMethod('getResponseByMessage');
        $returnType = $method->getReturnType();

        $this->assertSame('string', $returnType->getName());
    }

    public function testIsDeprecated(): void
    {
        $reflection = new \ReflectionClass(TelegramResponderInterface::class);
        $docComment = $reflection->getDocComment();

        $this->assertStringContainsString('@deprecated', $docComment);
    }
}
