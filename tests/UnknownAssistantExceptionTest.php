<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\UnknownAssistantException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnknownAssistantException::class)]
class UnknownAssistantExceptionTest extends TestCase
{
    public function testCanBeCreatedWithMessage(): void
    {
        $exception = new UnknownAssistantException('Assistant "xyz" not found');

        $this->assertSame('Assistant "xyz" not found', $exception->getMessage());
    }

    public function testExtendsException(): void
    {
        $exception = new UnknownAssistantException('error');

        $this->assertInstanceOf(\Exception::class, $exception);
    }

    public function testCanBeThrownAndCaught(): void
    {
        $caught = false;
        try {
            throw new UnknownAssistantException('unknown');
        } catch (UnknownAssistantException $e) {
            $caught = true;
            $this->assertSame('unknown', $e->getMessage());
        }

        $this->assertTrue($caught);
    }

    public function testCanBeCreatedWithEmptyMessage(): void
    {
        $exception = new UnknownAssistantException('');

        $this->assertSame('', $exception->getMessage());
    }

    public function testCanBeCreatedWithCode(): void
    {
        $exception = new UnknownAssistantException('error', 404);

        $this->assertSame(404, $exception->getCode());
    }
}
