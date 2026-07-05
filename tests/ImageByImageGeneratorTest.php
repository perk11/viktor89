<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\ImageGeneration\Automatic1111ImageApiResponse;
use Perk11\Viktor89\ImageGeneration\ImageByImageGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImageByImageGenerator::class)]
class ImageByImageGeneratorTest extends TestCase
{
    public function testIsInterface(): void
    {
        $reflection = new \ReflectionClass(ImageByImageGenerator::class);
        $this->assertTrue($reflection->isInterface());
    }

    public function testHasProcessImageMethod(): void
    {
        $reflection = new \ReflectionClass(ImageByImageGenerator::class);
        $method = $reflection->getMethod('processImage');
        $this->assertTrue($method->isAbstract());
    }

    public function testMethodParameters(): void
    {
        $reflection = new \ReflectionClass(ImageByImageGenerator::class);
        $method = $reflection->getMethod('processImage');
        $params = $method->getParameters();

        $this->assertCount(3, $params);
        $this->assertSame('imageContent', $params[0]->getName());
        $this->assertSame('string', $params[0]->getType()->getName());
        $this->assertSame('userId', $params[1]->getName());
        $this->assertSame('int', $params[1]->getType()->getName());
        $this->assertSame('prompt', $params[2]->getName());
    }

    public function testReturnsAutomatic1111ImageApiResponse(): void
    {
        $reflection = new \ReflectionClass(ImageByImageGenerator::class);
        $method = $reflection->getMethod('processImage');
        $returnType = $method->getReturnType();

        $this->assertSame(Automatic1111ImageApiResponse::class, $returnType->getName());
    }
}
