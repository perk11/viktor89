<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\ImageGeneration\Automatic1111ImageApiResponse;
use Perk11\Viktor89\ImageGeneration\ImageByPromptAndImageGenerator;
use Perk11\Viktor89\ImageGeneration\ImageGenerationPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImageByPromptAndImageGenerator::class)]
class ImageByPromptAndImageGeneratorTest extends TestCase
{
    public function testIsInterface(): void
    {
        $reflection = new \ReflectionClass(ImageByPromptAndImageGenerator::class);
        $this->assertTrue($reflection->isInterface());
    }

    public function testHasGenerateImageByPromptAndImagesMethod(): void
    {
        $reflection = new \ReflectionClass(ImageByPromptAndImageGenerator::class);
        $method = $reflection->getMethod('generateImageByPromptAndImages');
        $this->assertTrue($method->isAbstract());
    }

    public function testMethodParameters(): void
    {
        $reflection = new \ReflectionClass(ImageByPromptAndImageGenerator::class);
        $method = $reflection->getMethod('generateImageByPromptAndImages');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertSame('imageGenerationPrompt', $params[0]->getName());
        $this->assertSame(ImageGenerationPrompt::class, $params[0]->getType()->getName());
        $this->assertSame('userId', $params[1]->getName());
    }

    public function testReturnsAutomatic1111ImageApiResponse(): void
    {
        $reflection = new \ReflectionClass(ImageByPromptAndImageGenerator::class);
        $method = $reflection->getMethod('generateImageByPromptAndImages');
        $returnType = $method->getReturnType();

        $this->assertSame(Automatic1111ImageApiResponse::class, $returnType->getName());
    }
}
