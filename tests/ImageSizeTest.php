<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\ImageGeneration\ImageSize;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImageSize::class)]
class ImageSizeTest extends TestCase
{
    public function testConstructorStoresWidthAndHeight(): void
    {
        $size = new ImageSize(512, 512);

        $this->assertSame(512, $size->width);
        $this->assertSame(512, $size->height);
    }

    public function testConstructorWithDifferentDimensions(): void
    {
        $size = new ImageSize(768, 1024);

        $this->assertSame(768, $size->width);
        $this->assertSame(1024, $size->height);
    }

    public function testConstructorWithSquareDimensions(): void
    {
        $size = new ImageSize(1024, 1024);

        $this->assertSame(1024, $size->width);
        $this->assertSame(1024, $size->height);
    }
}
