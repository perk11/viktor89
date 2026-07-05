<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\ImageGeneration\ImageRepository::class)]
class ImageRepositoryTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\ImageGeneration\ImageRepository::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testHasRetrieveMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\ImageGeneration\ImageRepository::class);
        $method = $reflection->getMethod('retrieve');
        $params = $method->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('name', $params[0]->getName());
        $this->assertSame('string', $params[0]->getType()->getName());
    }

    public function testRetrieveReturnsNullableString(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\ImageGeneration\ImageRepository::class);
        $method = $reflection->getMethod('retrieve');
        $returnType = $method->getReturnType();
        $this->assertTrue($returnType->allowsNull());
    }

    public function testHasSaveMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\ImageGeneration\ImageRepository::class);
        $method = $reflection->getMethod('save');
        $params = $method->getParameters();
        $this->assertCount(3, $params);
        $this->assertSame('name', $params[0]->getName());
        $this->assertSame('string', $params[0]->getType()->getName());
        $this->assertSame('userId', $params[1]->getName());
        $this->assertSame('int', $params[1]->getType()->getName());
        $this->assertSame('fileContents', $params[2]->getName());
    }

    public function testSaveReturnsBool(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\ImageGeneration\ImageRepository::class);
        $method = $reflection->getMethod('save');
        $this->assertSame('bool', $method->getReturnType()->getName());
    }

    public function testHasFindAllPublicImagesMethod(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\ImageGeneration\ImageRepository::class);
        $method = $reflection->getMethod('findAllPublicImages');
        $returnType = $method->getReturnType();
        $this->assertSame('array', $returnType->getName());
    }

    public function testConstructorTakesSQLite3(): void
    {
        $reflection = new \ReflectionClass(\Perk11\Viktor89\ImageGeneration\ImageRepository::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame(\SQLite3::class, $params[0]->getType()->getName());
    }
}
