<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\IPC\DraftState;
use Perk11\Viktor89\IPC\DraftUpdater;
use Perk11\Viktor89\IPC\FinalMessageTracker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DraftUpdater::class)]
class DraftUpdaterTest extends TestCase
{
    public function testIsClass(): void
    {
        $reflection = new \ReflectionClass(DraftUpdater::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
    }

    public function testConstructorTakesFinalMessageTracker(): void
    {
        $reflection = new \ReflectionClass(DraftUpdater::class);
        $params = $reflection->getConstructor()->getParameters();

        $this->assertCount(2, $params);
        $this->assertSame('finalMessageTracker', $params[0]->getName());
        $this->assertSame(FinalMessageTracker::class, $params[0]->getType()->getName());
        $this->assertSame('refreshIntervalSeconds', $params[1]->getName());
        $this->assertSame(10.0, $params[1]->getDefaultValue());
    }

    public function testHasUpdateDraftMethod(): void
    {
        $reflection = new \ReflectionClass(DraftUpdater::class);
        $method = $reflection->getMethod('updateDraft');
        $params = $method->getParameters();

        $this->assertSame('void', $method->getReturnType()->getName());
        $this->assertCount(2, $params);
        $this->assertSame('workerId', $params[0]->getName());
        $this->assertSame('int', $params[0]->getType()->getName());
        $this->assertSame('draft', $params[1]->getName());
        $this->assertSame(DraftState::class, $params[1]->getType()->getName());
    }

    public function testHasRemoveDraftMethod(): void
    {
        $reflection = new \ReflectionClass(DraftUpdater::class);
        $method = $reflection->getMethod('removeDraft');
        $params = $method->getParameters();

        $this->assertSame('void', $method->getReturnType()->getName());
        $this->assertCount(1, $params);
        $this->assertSame('workerId', $params[0]->getName());
        $this->assertSame('int', $params[0]->getType()->getName());
    }

    public function testUpdateDraftIsNoOpWhenWorkerIsFinalizing(): void
    {
        // The core guarantee: once a worker's final message is being sent,
        // no draft must be sent for it, so a draft can never appear after the
        // actual message.
        $finalMessageTracker = new FinalMessageTracker();
        $finalMessageTracker->markWorkerFinalizing(7);
        $updater = new DraftUpdater($finalMessageTracker);

        // Should not throw and should not register any draft.
        $updater->updateDraft(7, new DraftState(-100, 1, 'late draft', 'Default'));

        $reflection = new \ReflectionClass(DraftUpdater::class);
        $workerDrafts = $reflection->getProperty('workerDrafts');
        $workerDrafts->setAccessible(true);

        $this->assertArrayNotHasKey(7, $workerDrafts->getValue($updater));
    }

    public function testRemoveDraftForUnknownWorkerDoesNotThrow(): void
    {
        $updater = new DraftUpdater(new FinalMessageTracker());

        $updater->removeDraft(999);

        $this->assertTrue(true);
    }
}
