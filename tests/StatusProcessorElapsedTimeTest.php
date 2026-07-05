<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Perk11\Viktor89\IPC\StatusProcessor::class)]
class StatusProcessorElapsedTimeTest extends TestCase
{
    public function testElapsedTimeZeroSeconds(): void
    {
        $dt = new \DateTimeImmutable();
        $now = new \DateTimeImmutable();
        $result = $this->computeElapsed($dt, $now);
        $this->assertStringStartsWith('0:', $result);
    }

    public function testElapsedTime65Seconds(): void
    {
        $dt = (new \DateTimeImmutable())->modify('-65 seconds');
        $now = new \DateTimeImmutable();
        $result = $this->computeElapsed($dt, $now);
        $this->assertStringStartsWith('1:', $result);
    }

    public function testElapsedTimeNegative(): void
    {
        $dt = (new \DateTimeImmutable())->modify('+10 seconds');
        $now = new \DateTimeImmutable();
        $result = $this->computeElapsed($dt, $now);
        $this->assertStringStartsWith('-', $result);
    }

    private function computeElapsed(\DateTimeImmutable $start, \DateTimeImmutable $now): string
    {
        $seconds = $now->getTimestamp() - $start->getTimestamp();
        $sign = $seconds < 0 ? '-' : '';
        $abs = abs($seconds);
        return $sign . intdiv($abs, 60) . ':' . str_pad((string)($abs % 60), 2, '0', STR_PAD_LEFT);
    }
}
