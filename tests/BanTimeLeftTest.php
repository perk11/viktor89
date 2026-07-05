<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\RateLimiting\BanTimeLeft;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BanTimeLeft::class)]
class BanTimeLeftTest extends TestCase
{
    public function testConstructorStoresChatIdAndTime(): void
    {
        $ban = new BanTimeLeft(-100123, 3600);

        $this->assertSame(-100123, $ban->chatId);
        $this->assertSame(3600, $ban->timeInSeconds);
    }

    public function testConstructorWithZeroTime(): void
    {
        $ban = new BanTimeLeft(-100456, 0);

        $this->assertSame(0, $ban->timeInSeconds);
    }

    public function testConstructorWithPositiveChatId(): void
    {
        $ban = new BanTimeLeft(12345678, 1800);

        $this->assertSame(12345678, $ban->chatId);
        $this->assertSame(1800, $ban->timeInSeconds);
    }
}
