<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\Util\Telegram\ReactionDeleter::class)]
class ReactionDeleterTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Util\Telegram\ReactionDeleter::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testHasDeleteMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Util\Telegram\ReactionDeleter::class);
        $method = $reflection->getMethod('delete');
        $params = $method->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('chatId', $params[0]->getName());
        $this->assertSame('int', $params[0]->getType()->getName());
        $this->assertSame('messageId', $params[1]->getName());
        $this->assertSame('int', $params[1]->getType()->getName());
    }

    public function testDeleteReturnsBool(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Util\Telegram\ReactionDeleter::class);
        $method = $reflection->getMethod('delete');
        $this->assertSame('bool', $method->getReturnType()->getName());
    }

    public function testConstructorTakesTelegramBotId(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Util\Telegram\ReactionDeleter::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('telegramBotId', $params[0]->getName());
        $this->assertSame('int', $params[0]->getType()->getName());
    }
}
