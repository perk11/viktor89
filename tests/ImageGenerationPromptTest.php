<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\ImageGeneration\ImageGenerationPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImageGenerationPrompt::class)]
class ImageGenerationPromptTest extends TestCase
{
    public function testConstructorWithTextOnly(): void
    {
        $prompt = new ImageGenerationPrompt('A beautiful sunset');

        $this->assertSame('A beautiful sunset', $prompt->text);
        $this->assertSame([], $prompt->sourceImagesContents);
    }

    public function testConstructorWithTextAndSourceImages(): void
    {
        $images = ['image1_data', 'image2_data'];
        $prompt = new ImageGenerationPrompt('Remix', $images);

        $this->assertSame('Remix', $prompt->text);
        $this->assertSame($images, $prompt->sourceImagesContents);
    }

    public function testConstructorWithEmptyText(): void
    {
        $prompt = new ImageGenerationPrompt('');

        $this->assertSame('', $prompt->text);
    }

    public function testConstructorWithEmptySourceImages(): void
    {
        $prompt = new ImageGenerationPrompt('Test', []);

        $this->assertSame([], $prompt->sourceImagesContents);
    }

    public function testConstructorWithMultipleSourceImages(): void
    {
        $images = ['data1', 'data2', 'data3'];
        $prompt = new ImageGenerationPrompt('Combine', $images);

        $this->assertCount(3, $prompt->sourceImagesContents);
    }
}
