<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\PreResponseProcessor\SavedImageNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SavedImageNotFoundException::class)]
class SavedImageNotFoundExceptionTest extends TestCase
{
    public function testCanBeCreatedWithMessage(): void
    {
        $exception = new SavedImageNotFoundException('Image "cat" not found');
        $this->assertSame('Image "cat" not found', $exception->getMessage());
    }

    public function testExtendsException(): void
    {
        $this->assertInstanceOf(\Exception::class, new SavedImageNotFoundException('test'));
    }

    public function testCanBeThrownAndCaught(): void
    {
        $caught = false;
        try {
            throw new SavedImageNotFoundException('missing');
        } catch (SavedImageNotFoundException $e) {
            $caught = true;
        }
        $this->assertTrue($caught);
    }
}
