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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Perk11\Viktor89\Test\Support\IntegrationTestDsl;
use Perk11\Viktor89\Test\Support\TelegramRecordingTrait;

use function Amp\async;
use function Amp\delay;

require_once __DIR__ . '/Support/IntegrationTestSupport.php';

/**
 * Exercises the new draft-refresh mechanism end to end: a worker forwards a
 * draft, and the main process (DraftUpdater) keeps it alive on a timer even
 * while the worker is blocked, replaces it when new content arrives, and stops
 * refreshing it once the task completes.
 */
#[CoversClass(DraftUpdater::class)]
#[CoversClass(RunningTaskTracker::class)]
class DraftRefreshIntegrationTest extends TestCase
{
    use TelegramRecordingTrait;

    private const float REFRESH = 0.1;

    protected function setUp(): void
    {
        $this->installRecordingTelegramClient();
    }

    public function testDraftIsRefreshedPeriodicallyWhileWorkerIsBlocked(): void
    {
        ob_start();
        try {
            async(fn () => $this->runRefreshScenario())->await();
        } finally {
            ob_end_clean();
        }

        $draftCalls = array_filter(
            $this->recordedCalls(),
            fn (array $call): bool => $this->isDraftAction($call['action']),
        );

        $this->assertGreaterThan(
            2,
            count($draftCalls),
            'A single draft should be refreshed several times while the worker is blocked',
        );

        $this->assertSame(
            [777],
            array_unique(array_column($draftCalls, 'draftId')),
            'Every refresh must target the same draft_id',
        );

        $this->assertCount(
            1,
            array_unique(array_column($draftCalls, 'text')),
            'Refreshed draft content must be unchanged',
        );
    }

    public function testDraftContentIsReplacedWhenNewDraftArrives(): void
    {
        ob_start();
        try {
            async(fn () => $this->runReplacementScenario())->await();
        } finally {
            ob_end_clean();
        }

        $draftCalls = array_filter(
            $this->recordedCalls(),
            fn (array $call): bool => $this->isDraftAction($call['action']),
        );

        $contents = array_column($draftCalls, 'text');

        $this->assertContains('draft content v1', $contents, 'The original draft should have been sent');
        $this->assertContains('draft content v2', $contents, 'The replacement draft should have been sent');
        $this->assertSame(
            'draft content v2',
            $contents[count($contents) - 1],
            'After replacement, refreshes must carry the new content',
        );
    }

    public function testDraftRefreshStopsAfterTaskCompletion(): void
    {
        ob_start();
        try {
            $counts = async(fn () => $this->runCompletionScenario())->await();
        } finally {
            ob_end_clean();
        }

        [$beforeWindow, $afterWindow] = $counts;
        $this->assertSame(
            $beforeWindow,
            $afterWindow,
            'No draft may be refreshed after the task completes and its timer is cancelled',
        );
    }

    public function testDraftSendingPausesOnRateLimitThenResumes(): void
    {
        $draftCallIndex = 0;
        $this->telegramResponseOverride = function (string $action, array $form) use (&$draftCallIndex): ?array {
            if ($action !== 'sendMessageDraft' && $action !== 'sendRichMessageDraft') {
                return null;
            }
            // Rate-limit only the very first draft send; subsequent ones succeed.
            if ($draftCallIndex++ === 0) {
                return [
                    'ok' => false,
                    'error_code' => 429,
                    'description' => 'Too Many Requests',
                    'parameters' => ['retry_after' => 1],
                ];
            }

            return null;
        };

        ob_start();
        try {
            [$duringPause, $afterResume] = async(fn () => $this->runRateLimitScenario())->await();
        } finally {
            ob_end_clean();
        }

        $this->assertSame(
            1,
            $duringPause,
            'Only the initial (rate-limited) draft call should occur during the pause window',
        );
        $this->assertGreaterThan(
            $duringPause,
            $afterResume,
            'Draft sending must resume once the rate-limit window elapses',
        );
    }

    private function runRefreshScenario(): void
    {
        [$workerChannel, $mainChannel] = $this->wireTracker();

        $workerChannel->send(new DraftUpdateMessage(
            1,
            new DraftState(chatId: 111, draftId: 777, text: 'draft content', parseMode: 'Default'),
        ));
        // Worker is now "blocked" (e.g. model thinking); refreshes must come
        // purely from the DraftUpdater timer.
        delay(self::REFRESH * 3.5);

        $workerChannel->send(new TaskCompletedMessage(1));
        delay(self::REFRESH * 0.5);
        $workerChannel->close();
    }

    private function runReplacementScenario(): void
    {
        [$workerChannel] = $this->wireTracker();

        $workerChannel->send(new DraftUpdateMessage(
            1,
            new DraftState(chatId: 222, draftId: 888, text: 'draft content v1', parseMode: 'Default'),
        ));
        delay(self::REFRESH * 1.5);

        $workerChannel->send(new DraftUpdateMessage(
            1,
            new DraftState(chatId: 222, draftId: 888, text: 'draft content v2', parseMode: 'Default'),
        ));
        delay(self::REFRESH * 2.5);

        $workerChannel->send(new TaskCompletedMessage(1));
        delay(self::REFRESH * 0.5);
        $workerChannel->close();
    }

    /** @return array{0: int, 1: int} draft-call counts [before window, after window] */
    private function runCompletionScenario(): array
    {
        [$workerChannel] = $this->wireTracker();

        $workerChannel->send(new DraftUpdateMessage(
            1,
            new DraftState(chatId: 333, draftId: 999, text: 'draft content', parseMode: 'Default'),
        ));
        delay(self::REFRESH * 2);

        $workerChannel->send(new TaskCompletedMessage(1));
        // Give the main process time to process completion and cancel the timer.
        delay(self::REFRESH * 0.5);
        $beforeWindow = $this->draftCallCount();

        // Observation window: a timer that was not cancelled would fire ~4 times here.
        delay(self::REFRESH * 4);
        $afterWindow = $this->draftCallCount();

        $workerChannel->close();

        return [$beforeWindow, $afterWindow];
    }

    private function isDraftAction(string $action): bool
    {
        return $action === 'sendMessageDraft' || $action === 'sendRichMessageDraft';
    }

    /** @return array{0: int, 1: int} draft-call counts [during pause, after resume] */
    private function runRateLimitScenario(): array
    {
        [$workerChannel] = $this->wireTracker();

        $workerChannel->send(new DraftUpdateMessage(
            1,
            new DraftState(chatId: 444, draftId: 111, text: 'draft content', parseMode: 'Default'),
        ));
        // The immediate send is rate-limited (retry_after = 1s); refreshes must
        // be skipped while paused.
        delay(0.6);
        $duringPause = $this->draftCallCount();

        // Past the 1s rate-limit window, refreshes must resume.
        delay(1.1);
        $afterResume = $this->draftCallCount();

        $workerChannel->send(new TaskCompletedMessage(1));
        delay(self::REFRESH * 0.5);
        $workerChannel->close();

        return [$duringPause, $afterResume];
    }

    private function draftCallCount(): int
    {
        return count(array_filter(
            $this->recordedCalls(),
            fn (array $call): bool => $this->isDraftAction($call['action']),
        ));
    }

    /** @return array{0: \Amp\Sync\Channel, 1: \Amp\Sync\Channel} */
    private function wireTracker(): array
    {
        [$workerChannel, $mainChannel] = IntegrationTestDsl::createChannelPair();

        $finalMessageTracker = new FinalMessageTracker();
        $draftUpdater = new DraftUpdater($finalMessageTracker, self::REFRESH, logger: new \Psr\Log\NullLogger());
        $chatActionUpdater = new ChatActionUpdater($finalMessageTracker, 999, logger: new \Psr\Log\NullLogger());
        $runningTaskTracker = new RunningTaskTracker($chatActionUpdater, $draftUpdater, $finalMessageTracker, logger: new \Psr\Log\NullLogger());

        $execution = IntegrationTestDsl::makeExecution($mainChannel);
        async(static fn () => $runningTaskTracker->receive($execution));

        return [$workerChannel, $mainChannel];
    }
}
