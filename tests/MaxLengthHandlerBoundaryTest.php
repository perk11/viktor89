<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\AbortStreamingResponse\MaxLengthHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MaxLengthHandler::class)]
class MaxLengthHandlerBoundaryTest extends TestCase
{
    public function testReturnsFalseForZeroLengthResponse(): void
    {
        $handler = new MaxLengthHandler(new \Psr\Log\NullLogger(), 100);
        $result = $handler->getNewResponse('prompt', '');
        $this->assertFalse($result);
    }

    public function testReturnsFalseForSingleCharUnderLimit(): void
    {
        $handler = new MaxLengthHandler(new \Psr\Log\NullLogger(), 100);
        $result = $handler->getNewResponse('prompt', 'a');
        $this->assertFalse($result);
    }

    public function testTruncatesToExactLimit(): void
    {
        $handler = new MaxLengthHandler(new \Psr\Log\NullLogger(), 10);
        $result = $handler->getNewResponse('prompt', str_repeat('x', 20));
        $this->assertSame(10, mb_strlen($result));
    }

    public function testWithVeryLargeLimit(): void
    {
        $handler = new MaxLengthHandler(new \Psr\Log\NullLogger(), 1000000);
        $result = $handler->getNewResponse('prompt', 'short');
        $this->assertFalse($result);
    }

    public function testWithLimitOfOne(): void
    {
        $handler = new MaxLengthHandler(new \Psr\Log\NullLogger(), 1);
        $result = $handler->getNewResponse('prompt', 'ab');
        $this->assertSame('a', $result);
    }

    public function testMultiByteCharactersCountedCorrectly(): void
    {
        $handler = new MaxLengthHandler(new \Psr\Log\NullLogger(), 3);
        $result = $handler->getNewResponse('prompt', '👋🌍🔥🚀');
        $this->assertSame('👋🌍🔥', $result);
    }

    public function testMixedAsciiAndUnicode(): void
    {
        $handler = new MaxLengthHandler(new \Psr\Log\NullLogger(), 4);
        $result = $handler->getNewResponse('prompt', 'ab👋🌍cd');
        $this->assertSame('ab👋🌍', $result);
    }
}
