<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\HistoryReader::class)]
class HistoryReaderTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\HistoryReader::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testHasGetPreviousMessagesMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\HistoryReader::class);
        $method = $reflection->getMethod('getPreviousMessages');
        $params = $method->getParameters();
        $this->assertCount(4, $params);
        $this->assertSame('message', $params[0]->getName());
        $this->assertSame(\Longman\TelegramBot\Entities\Message::class, $params[0]->getType()->getName());
        $this->assertSame('chainMessageToInclude', $params[1]->getName());
        $this->assertSame('int', $params[1]->getType()->getName());
        $this->assertSame('totalMessageToInclude', $params[2]->getName());
        $this->assertSame('maxMessageFromHistoryToInclude', $params[3]->getName());
    }

    public function testGetPreviousMessagesReturnsArray(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\HistoryReader::class);
        $method = $reflection->getMethod('getPreviousMessages');
        $returnType = $method->getReturnType();
        $this->assertSame('array', $returnType->getName());
    }

    public function testConstructorTakesMessageRepository(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\HistoryReader::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame(\Perk11\Viktor89\Repository\MessageRepository::class, $params[0]->getType()->getName());
    }
}
