<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\OpenAiContextParsingException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenAiContextParsingException::class)]
class OpenAiContextParsingExceptionTest extends TestCase
{
    public function testCanBeCreatedWithMessage(): void
    {
        $exception = new OpenAiContextParsingException('Parsing failed');

        $this->assertSame('Parsing failed', $exception->getMessage());
    }

    public function testCanBeCreatedWithMessageAndCode(): void
    {
        $exception = new OpenAiContextParsingException('Error', 400);

        $this->assertSame('Error', $exception->getMessage());
        $this->assertSame(400, $exception->getCode());
    }

    public function testExtendsException(): void
    {
        $exception = new OpenAiContextParsingException('test');

        $this->assertInstanceOf(\Exception::class, $exception);
    }

    public function testCanBeThrownAndCaught(): void
    {
        $caught = false;
        try {
            throw new OpenAiContextParsingException('context error');
        } catch (OpenAiContextParsingException $e) {
            $caught = true;
            $this->assertSame('context error', $e->getMessage());
        }

        $this->assertTrue($caught);
    }

    public function testCanBeCaughtAsException(): void
    {
        $caught = false;
        try {
            throw new OpenAiContextParsingException('error');
        } catch (\Exception $e) {
            $caught = true;
            $this->assertInstanceOf(OpenAiContextParsingException::class, $e);
        }

        $this->assertTrue($caught);
    }
}
