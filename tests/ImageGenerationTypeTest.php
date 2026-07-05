<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\ImageGeneration\ImageGenerationType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImageGenerationType::class)]
class ImageGenerationTypeTest extends TestCase
{
    public function testImg2imgCaseExists(): void
    {
        $this->assertSame(ImageGenerationType::img2img, ImageGenerationType::img2img);
        $this->assertSame('img2img', ImageGenerationType::img2img->name);
    }

    public function testTxt2imgCaseExists(): void
    {
        $this->assertSame(ImageGenerationType::txt2img, ImageGenerationType::txt2img);
        $this->assertSame('txt2img', ImageGenerationType::txt2img->name);
    }

    public function testCasesAreDistinct(): void
    {
        $this->assertNotSame(ImageGenerationType::img2img, ImageGenerationType::txt2img);
    }

    public function testEnumHasTwoCases(): void
    {
        $this->assertCount(2, ImageGenerationType::cases());
    }
}
