<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\ImageGeneration\ImageRemixer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImageRemixer::class)]
class ImageRemixerTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(ImageRemixer::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testHasRemixImageMethod(): void
    {
        $reflection = new \ReflectionClass(ImageRemixer::class);
        $method = $reflection->getMethod('remixImage');
        $this->assertFalse($method->isAbstract());
    }

    public function testRemixImageTakesImageAndUserId(): void
    {
        $reflection = new \ReflectionClass(ImageRemixer::class);
        $method = $reflection->getMethod('remixImage');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertSame('image', $params[0]->getName());
        $this->assertSame('string', $params[0]->getType()->getName());
        $this->assertSame('userId', $params[1]->getName());
        $this->assertSame('int', $params[1]->getType()->getName());
    }

    public function testReturnsAutomatic1111ImageApiResponse(): void
    {
        $reflection = new \ReflectionClass(ImageRemixer::class);
        $method = $reflection->getMethod('remixImage');
        $returnType = $method->getReturnType();

        $this->assertSame(
            \Perk11\Viktor89\ImageGeneration\Automatic1111ImageApiResponse::class,
            $returnType->getName()
        );
    }
}
