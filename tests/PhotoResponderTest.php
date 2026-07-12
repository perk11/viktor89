<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\ImageGeneration\PhotoResponder::class)]
class PhotoResponderTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\ImageGeneration\PhotoResponder::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testHasSendPhotoMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\ImageGeneration\PhotoResponder::class);
        $method = $reflection->getMethod('sendPhoto');
        $params = $method->getParameters();
        $this->assertCount(4, $params);
        $this->assertSame('message', $params[0]->getName());
        $this->assertSame('photoContents', $params[1]->getName());
        $this->assertSame('string', $params[1]->getType()->getName());
        $this->assertSame('sendAsWebp', $params[2]->getName());
        $this->assertSame('bool', $params[2]->getType()->getName());
    }

    public function testSendPhotoReturnsVoid(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\ImageGeneration\PhotoResponder::class);
        $method = $reflection->getMethod('sendPhoto');
        $this->assertSame('void', $method->getReturnType()->getName());
    }

    public function testConstructorTakesThreeParameters(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\ImageGeneration\PhotoResponder::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();
        $this->assertCount(4, $params);
        $this->assertSame(\Perk11\Viktor89\Repository\MessageRepository::class, $params[0]->getType()->getName());
        $this->assertSame(\Perk11\Viktor89\CacheFileManager::class, $params[1]->getType()->getName());
        $this->assertSame(\Perk11\Viktor89\Util\Telegram\ReactionReplacer::class, $params[2]->getType()->getName());
        $this->assertSame(\Perk11\Viktor89\Repository\MessageMetadataRepository::class, $params[3]->getType()->getName());
        $this->assertTrue($params[3]->getType()->allowsNull());
    }

    public function testHasNeedsSpoilerPrivateMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\ImageGeneration\PhotoResponder::class);
        $method = $reflection->getMethod('needsSpoiler');
        $this->assertTrue($method->isPrivate());
        $this->assertSame('bool', $method->getReturnType()->getName());
    }
}
