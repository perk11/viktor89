<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\ImageGeneration\SavedImage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SavedImage::class)]
class SavedImageTest extends TestCase
{
    public function testConstructorWithAllFields(): void
    {
        $image = new SavedImage(1, 'sunset.jpg', '/data/images/sunset.jpg', 12345, '2024-01-01 12:00:00', false);

        $this->assertSame(1, $image->id);
        $this->assertSame('sunset.jpg', $image->name);
        $this->assertSame('/data/images/sunset.jpg', $image->filename);
        $this->assertSame(12345, $image->userId);
        $this->assertSame('2024-01-01 12:00:00', $image->createdAt);
        $this->assertFalse($image->private);
    }

    public function testConstructorWithPrivateImage(): void
    {
        $image = new SavedImage(2, 'private.png', '/data/private.png', 67890, '2024-06-15 08:30:00', true);

        $this->assertTrue($image->private);
    }

    public function testConstructorWithDifferentFileTypes(): void
    {
        $image = new SavedImage(3, 'render.png', '/data/render.png', 11111, '2024-03-20 14:00:00', false);

        $this->assertSame('render.png', $image->name);
    }

    public function testConstructorWithLargeId(): void
    {
        $image = new SavedImage(999999, 'large_id.jpg', '/data/large.jpg', 999999, '2024-12-31 23:59:59', false);

        $this->assertSame(999999, $image->id);
    }
}
