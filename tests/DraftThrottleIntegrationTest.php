<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\IPC\ChatActionUpdater;
use Perk11\Viktor89\IPC\DraftState;
use Perk11\Viktor89\IPC\DraftUpdateMessage;
use Perk11\Viktor89\IPC\DraftUpdater;
use Perk11\Viktor89\IPC\FinalMessageTracker;
use Perk11\Viktor89\IPC\RunningTaskTracker;
use Perk11\Viktor89\IPC\TaskCompletedMessage;
use Perk11\Viktor89\Test\Support\IntegrationTestDsl;
use Perk11\Viktor89\Test\Support\TelegramRecordingTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

require_once __DIR__ . '/Support/IntegrationTestSupport.php';

/**
 * Verifies the per-chat send throttle: when a worker streams many draft
 * updates faster than the allowed rate, only the permitted number are sent
 * straight away and the rest are deferred (and coalesced) so that only the
 * latest content is delivered once a slot frees up.
 */
#[CoversClass(DraftUpdater::class)]
class DraftThrottleIntegrationTest extends TestCase
{
    use TelegramRecordingTrait;

    protected function setUp(): void
    {
        $this->installRecordingTelegramClient();
    }

    public function testRapidDraftUpdatesAreThrottledAndCoalesced(): void
    {
        ob_start();
        try {
            [$firstWindowCalls, $allCalls] = async(fn () => $this->runThrottleScenario())->await();
        } finally {
            ob_end_clean();
        }

        // The default throttle is 3 sends / second. A burst of 6 updates must
        // produce exactly 3 sends within the first window ...
        $this->assertCount(
            3,
            $firstWindowCalls,
            'No more than 3 draft sends may occur within the first throttle window',
        );
        $this->assertSame(
            ['chunk1', 'chunk2', 'chunk3'],
            array_column($firstWindowCalls, 'text'),
            'The first three updates must be sent immediately, in arrival order',
        );

        // ... and the excess must not be dropped, but delivered later carrying
        // only the latest content (superseded intermediate content is never sent).
        $allTexts = array_column($allCalls, 'text');
        $this->assertContains('chunk6', $allTexts, 'The latest throttled update must eventually be sent');
        $this->assertNotContains('chunk4', $allTexts, 'Superseded throttled content must be coalesced away');
        $this->assertNotContains('chunk5', $allTexts, 'Superseded throttled content must be coalesced away');
    }

    public function testRapidEditUpdatesAreThrottledAndCoalesced(): void
    {
        ob_start();
        try {
            [$firstWindowCalls, $allCalls] = async(fn () => $this->runEditThrottleScenario())->await();
        } finally {
            ob_end_clean();
        }

        // The group-chat edit path shares the same per-chat throttle as drafts.
        $this->assertCount(
            3,
            $firstWindowCalls,
            'No more than 3 edits may occur within the first throttle window',
        );
        $this->assertSame(
            ['chunk1', 'chunk2', 'chunk3'],
            array_column($firstWindowCalls, 'text'),
            'The first three edits must be applied immediately, in arrival order',
        );

        $allTexts = array_column($allCalls, 'text');
        $this->assertContains('chunk6', $allTexts, 'The latest throttled edit must eventually be applied');
        $this->assertNotContains('chunk4', $allTexts, 'Superseded throttled content must be coalesced away');
        $this->assertNotContains('chunk5', $allTexts, 'Superseded throttled content must be coalesced away');
    }

    /** @return array{0: list<array>, 1: list<array>} [firstWindowCalls, allCalls] */
    private function runThrottleScenario(): array
    {
        [$workerChannel] = $this->wireTracker();

        // Fire 6 distinct updates far faster than the 3/sec limit. The receiver
        // processes them all in a single event-loop tick once we yield below.
        for ($i = 1; $i <= 6; ++$i) {
            $workerChannel->send(new DraftUpdateMessage(
                1,
                new DraftState(chatId: 555, draftId: 1, text: "chunk$i", parseMode: 'Default'),
            ));
        }

        // First throttle window (1s): only the 3 immediate sends happen here,
        // the throttled ones are deferred to the next free slot.
        delay(0.5);
        $firstWindowCalls = $this->draftCalls();

        // Past the 1s window the deferred (coalesced) send fires, delivering the
        // latest content.
        delay(0.8);
        $allCalls = $this->draftCalls();

        $workerChannel->send(new TaskCompletedMessage(1));
        delay(0.2);
        $workerChannel->close();

        return [$firstWindowCalls, $allCalls];
    }

    /** @return list<array{action: string, text: ?string}> */
    private function draftCalls(): array
    {
        return array_values(array_filter(
            $this->recordedCalls(),
            fn (array $call): bool => $this->isDraftAction($call['action']),
        ));
    }

    private function isDraftAction(string $action): bool
    {
        return $action === 'sendMessageDraft' || $action === 'sendRichMessageDraft';
    }

    /** @return array{0: list<array>, 1: list<array>} [firstWindowCalls, allCalls] */
    private function runEditThrottleScenario(): array
    {
        [$workerChannel] = $this->wireTracker();

        // The edit path targets an existing message (editMessageId) instead of
        // a draft, and never starts a refresh timer.
        for ($i = 1; $i <= 6; ++$i) {
            $workerChannel->send(new DraftUpdateMessage(
                1,
                new DraftState(chatId: -600, draftId: null, text: "chunk$i", parseMode: 'Default', editMessageId: 42),
            ));
        }

        delay(0.5);
        $firstWindowCalls = $this->editCalls();

        delay(0.8);
        $allCalls = $this->editCalls();

        $workerChannel->send(new TaskCompletedMessage(1));
        delay(0.2);
        $workerChannel->close();

        return [$firstWindowCalls, $allCalls];
    }

    /** @return list<array{action: string, text: ?string}> */
    private function editCalls(): array
    {
        return array_values(array_filter(
            $this->recordedCalls(),
            fn (array $call): bool => $call['action'] === 'editMessageText',
        ));
    }

    /** @return array{0: \Amp\Sync\Channel, 1: \Amp\Sync\Channel} */
    private function wireTracker(): array
    {
        [$workerChannel, $mainChannel] = IntegrationTestDsl::createChannelPair();

        $finalMessageTracker = new FinalMessageTracker();
        $draftUpdater = new DraftUpdater($finalMessageTracker, 0.1, logger: new \Psr\Log\NullLogger());
        $chatActionUpdater = new ChatActionUpdater($finalMessageTracker, 999, logger: new \Psr\Log\NullLogger());
        $runningTaskTracker = new RunningTaskTracker($chatActionUpdater, $draftUpdater, $finalMessageTracker, logger: new \Psr\Log\NullLogger());

        $execution = IntegrationTestDsl::makeExecution($mainChannel);
        async(static fn () => $runningTaskTracker->receive($execution));

        return [$workerChannel, $mainChannel];
    }
}
