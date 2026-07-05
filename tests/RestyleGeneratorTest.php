<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\ImageGeneration\RestyleGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RestyleGenerator::class)]
class RestyleGeneratorTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(RestyleGenerator::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testImplementsImageByImageGenerator(): void
    {
        $reflection = new \ReflectionClass(RestyleGenerator::class);
        $this->assertTrue(
            $reflection->implementsInterface(\Perk11\Viktor89\ImageGeneration\ImageByImageGenerator::class)
        );
    }

    public function testHasProcessImageMethod(): void
    {
        $reflection = new \ReflectionClass(RestyleGenerator::class);
        $method = $reflection->getMethod('processImage');
        $this->assertFalse($method->isAbstract());
    }
}
