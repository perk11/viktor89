<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\ImageGeneration\Automatic1111ImageApiResponse;
use Perk11\Viktor89\ImageGeneration\ImageByPromptGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImageByPromptGenerator::class)]
class ImageByPromptGeneratorTest extends TestCase
{
    public function testIsInterface(): void
    {
        $reflection = new \ReflectionClass(ImageByPromptGenerator::class);
        $this->assertTrue($reflection->isInterface());
    }

    public function testHasGenerateImageByPromptMethod(): void
    {
        $reflection = new \ReflectionClass(ImageByPromptGenerator::class);
        $method = $reflection->getMethod('generateImageByPrompt');
        $this->assertTrue($method->isAbstract());
    }

    public function testMethodParameters(): void
    {
        $reflection = new \ReflectionClass(ImageByPromptGenerator::class);
        $method = $reflection->getMethod('generateImageByPrompt');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertSame('prompt', $params[0]->getName());
        $this->assertSame('string', $params[0]->getType()->getName());
        $this->assertSame('userId', $params[1]->getName());
        $this->assertSame('int', $params[1]->getType()->getName());
    }

    public function testReturnsAutomatic1111ImageApiResponse(): void
    {
        $reflection = new \ReflectionClass(ImageByPromptGenerator::class);
        $method = $reflection->getMethod('generateImageByPrompt');
        $returnType = $method->getReturnType();

        $this->assertSame(Automatic1111ImageApiResponse::class, $returnType->getName());
    }
}
