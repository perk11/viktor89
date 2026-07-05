<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\ImageGeneration\ImageGeneratorBadRequestException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImageGeneratorBadRequestException::class)]
class ImageGeneratorBadRequestExceptionTest extends TestCase
{
    public function testCanBeCreatedWithMessage(): void
    {
        $exception = new ImageGeneratorBadRequestException('Bad parameters');
        $this->assertSame('Bad parameters', $exception->getMessage());
    }

    public function testExtendsException(): void
    {
        $this->assertInstanceOf(\Exception::class, new ImageGeneratorBadRequestException('test'));
    }

    public function testCanBeCaughtAsRuntimeException(): void
    {
        $caught = false;
        try {
            throw new ImageGeneratorBadRequestException('error');
        } catch (\Exception $e) {
            $caught = true;
            $this->assertInstanceOf(ImageGeneratorBadRequestException::class, $e);
        }
        $this->assertTrue($caught);
    }
}
