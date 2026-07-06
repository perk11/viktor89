<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\IPC\FinalMessageTracker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FinalMessageTracker::class)]
class FinalMessageTrackerTest extends TestCase
{
    public function testWorkerIsNotFinalizingByDefault(): void
    {
        $tracker = new FinalMessageTracker();

        $this->assertFalse($tracker->isFinalMessageBeingSentByWorker(1));
    }

    public function testMarkWorkerFinalizing(): void
    {
        $tracker = new FinalMessageTracker();

        $tracker->markWorkerFinalizing(5);

        $this->assertTrue($tracker->isFinalMessageBeingSentByWorker(5));
    }

    public function testMarkingOneWorkerDoesNotAffectOthers(): void
    {
        $tracker = new FinalMessageTracker();

        $tracker->markWorkerFinalizing(5);

        $this->assertTrue($tracker->isFinalMessageBeingSentByWorker(5));
        $this->assertFalse($tracker->isFinalMessageBeingSentByWorker(6));
    }

    public function testClearWorker(): void
    {
        $tracker = new FinalMessageTracker();
        $tracker->markWorkerFinalizing(5);

        $tracker->clearWorker(5);

        $this->assertFalse($tracker->isFinalMessageBeingSentByWorker(5));
    }

    public function testClearWorkerOnlyAffectsGivenWorker(): void
    {
        $tracker = new FinalMessageTracker();
        $tracker->markWorkerFinalizing(5);
        $tracker->markWorkerFinalizing(6);

        $tracker->clearWorker(5);

        $this->assertFalse($tracker->isFinalMessageBeingSentByWorker(5));
        $this->assertTrue($tracker->isFinalMessageBeingSentByWorker(6));
    }

    public function testClearUnknownWorkerDoesNotThrow(): void
    {
        $tracker = new FinalMessageTracker();

        $tracker->clearWorker(999);

        $this->assertTrue(true);
    }
}
