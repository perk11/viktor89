<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\VoiceRecognition\NothingToTranscribeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NothingToTranscribeException::class)]
class NothingToTranscribeExceptionTest extends TestCase
{
    public function testCanBeCreatedWithMessage(): void
    {
        $exception = new NothingToTranscribeException('No audio to transcribe');

        $this->assertSame('No audio to transcribe', $exception->getMessage());
    }

    public function testExtendsException(): void
    {
        $exception = new NothingToTranscribeException('error');

        $this->assertInstanceOf(\Exception::class, $exception);
    }

    public function testCanBeThrownAndCaught(): void
    {
        $caught = false;
        try {
            throw new NothingToTranscribeException('nothing');
        } catch (NothingToTranscribeException $e) {
            $caught = true;
            $this->assertSame('nothing', $e->getMessage());
        }

        $this->assertTrue($caught);
    }

    public function testCanBeCaughtAsException(): void
    {
        $caught = false;
        try {
            throw new NothingToTranscribeException('error');
        } catch (\Exception $e) {
            $caught = true;
            $this->assertInstanceOf(NothingToTranscribeException::class, $e);
        }

        $this->assertTrue($caught);
    }

    public function testCanBeCreatedWithCode(): void
    {
        $exception = new NothingToTranscribeException('no audio', 500);

        $this->assertSame(500, $exception->getCode());
    }
}
