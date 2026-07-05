<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\ProcessingResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProcessingResult::class)]
class ProcessingResultTest extends TestCase
{
    public function testConstructorWithResponseAndAbort(): void
    {
        $message = new InternalMessage();
        $result = new ProcessingResult($message, true);

        $this->assertSame($message, $result->response);
        $this->assertTrue($result->abortProcessing);
        $this->assertNull($result->reaction);
        $this->assertNull($result->messageToReactTo);
    }

    public function testConstructorWithReaction(): void
    {
        $message = new InternalMessage();
        $reactMessage = new InternalMessage();
        $result = new ProcessingResult($message, false, '👍', $reactMessage);

        $this->assertSame('👍', $result->reaction);
        $this->assertSame($reactMessage, $result->messageToReactTo);
    }

    public function testConstructorWithCallback(): void
    {
        $result = new ProcessingResult(null, false, null, null, fn() => 'called');

        $this->assertNotNull($result->callback);
        $this->assertSame('called', ($result->callback)());
    }

    public function testConstructorWithNullResponse(): void
    {
        $result = new ProcessingResult(null, false);

        $this->assertNull($result->response);
    }

    public function testConstructorDefaults(): void
    {
        $result = new ProcessingResult(null, false);

        $this->assertNull($result->reaction);
        $this->assertNull($result->messageToReactTo);
        $this->assertNull($result->callback);
    }

    public function testConstructorWithAbortFalse(): void
    {
        $message = new InternalMessage();
        $result = new ProcessingResult($message, false);

        $this->assertFalse($result->abortProcessing);
    }
}
