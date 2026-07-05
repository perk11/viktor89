<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\RateLimiting\RateLimit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RateLimit::class)]
class RateLimitTest extends TestCase
{
    public function testConstructorStoresChatIdAndMaxMessages(): void
    {
        $limit = new RateLimit(-100123, 10);

        $this->assertSame(-100123, $limit->chatId);
        $this->assertSame(10, $limit->maxMessages);
    }

    public function testConstructorWithHighLimit(): void
    {
        $limit = new RateLimit(-100456, 100);

        $this->assertSame(100, $limit->maxMessages);
    }

    public function testConstructorWithPositiveChatId(): void
    {
        $limit = new RateLimit(12345678, 5);

        $this->assertSame(12345678, $limit->chatId);
    }
}
