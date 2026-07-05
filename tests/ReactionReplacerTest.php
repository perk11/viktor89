<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\Util\Telegram\ReactionReplacer::class)]
class ReactionReplacerTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Util\Telegram\ReactionReplacer::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testHasDeleteOrReplaceWithMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Util\Telegram\ReactionReplacer::class);
        $method = $reflection->getMethod('deleteOrReplaceWith');
        $params = $method->getParameters();
        $this->assertCount(3, $params);
        $this->assertSame('chatId', $params[0]->getName());
        $this->assertSame('int', $params[0]->getType()->getName());
        $this->assertSame('messageId', $params[1]->getName());
        $this->assertSame('int', $params[1]->getType()->getName());
        $this->assertSame('emoji', $params[2]->getName());
        $this->assertSame('string', $params[2]->getType()->getName());
    }

    public function testDeleteOrReplaceWithReturnsVoid(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Util\Telegram\ReactionReplacer::class);
        $method = $reflection->getMethod('deleteOrReplaceWith');
        $this->assertSame('void', $method->getReturnType()->getName());
    }

    public function testConstructorTakesReactionDeleter(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\Util\Telegram\ReactionReplacer::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame(\Perk11\Viktor89\Util\Telegram\ReactionDeleter::class, $params[0]->getType()->getName());
    }
}
