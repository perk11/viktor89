<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\IPC\ChatActionUpdater;
use Perk11\Viktor89\IPC\DraftState;
use Perk11\Viktor89\IPC\DraftUpdateMessage;
use Perk11\Viktor89\IPC\DraftUpdater;
use Perk11\Viktor89\IPC\FinalMessageTracker;
use Perk11\Viktor89\IPC\RunningTaskTracker;
use Perk11\Viktor89\IPC\TaskUpdateMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Perk11\Viktor89\Test\Support\IntegrationTestDsl;
use Perk11\Viktor89\Test\Support\TelegramRecordingTrait;

use function Amp\async;
use function Amp\delay;

require_once __DIR__ . '/Support/IntegrationTestSupport.php';

/**
 * Reproduces the "draft / typing appears after the worker is gone" bug.
 *
 * When a worker process dies abruptly (fatal error, OOM kill, segfault) its
 * run() finally{} never runs, so no TaskCompletedMessage is sent and the IPC
 * channel simply closes. RunningTaskTracker::receive() catches the resulting
 * ChannelException and returns — but used to do so WITHOUT cleaning up that
 * worker's draft / typing / finalizing state. The worker's draft refresh timer
 * (private chats) or deferred edit flush (group chats) then keeps firing on
 * behalf of a worker that no longer exists, so a stale draft/edit can appear
 * after whatever real messages have since been sent.
 */
#[CoversClass(RunningTaskTracker::class)]
#[CoversClass(DraftUpdater::class)]
#[CoversClass(ChatActionUpdater::class)]
class WorkerChannelCloseCleanupIntegrationTest extends TestCase
{
    use TelegramRecordingTrait;

    private const float REFRESH = 0.1;
    private const float TYPING_INTERVAL = 0.1;
    private const int DRAFT_CHAT = 5111;
    private const int TYPING_CHAT = 5222;

    protected function setUp(): void
    {
        $this->installRecordingTelegramClient();
    }

    public function testDraftStopsRefreshingWhenWorkerChannelClosesAbruptly(): void
    {
        ob_start();
        try {
            $refreshesAfterClose = async(fn () => $this->runDraftScenario())->await();
        } finally {
            ob_end_clean();
        }

        $this->assertSame(
            0,
            $refreshesAfterClose,
            'No draft may be refreshed after the worker channel closed without TaskCompleted',
        );
    }

    public function testTypingStopsWhenWorkerChannelClosesAbruptly(): void
    {
        ob_start();
        try {
            $typingAfterClose = async(fn () => $this->runTypingScenario())->await();
        } finally {
            ob_end_clean();
        }

        $this->assertSame(
            0,
            $typingAfterClose,
            'No typing notification may be sent after the worker channel closed without TaskCompleted',
        );
    }

    /** @return int number of draft refreshes for this chat after the channel closed */
    private function runDraftScenario(): int
    {
        [$workerChannel] = $this->wireTracker();

        $workerChannel->send(new DraftUpdateMessage(
            1,
            new DraftState(chatId: self::DRAFT_CHAT, draftId: 777, text: 'partial response', parseMode: 'Default'),
        ));
        delay(self::REFRESH * 2);
        // Sanity: the draft was actually being refreshed before the crash.
        $this->assertGreaterThan(0, $this->draftCallCount());

        // Worker dies: channel closes, no TaskCompletedMessage is ever sent.
        $workerChannel->close();

        $before = $this->draftCallCount();
        delay(self::REFRESH * 4); // a timer that survived would fire several times here
        return $this->draftCallCount() - $before;
    }

    /** @return int number of typing notifications for this chat after the channel closed */
    private function runTypingScenario(): int
    {
        [$workerChannel] = $this->wireTracker();

        $workerChannel->send(new TaskUpdateMessage(
            1,
            'Assistant',
            'thinking',
            IntegrationTestDsl::typingAction(self::TYPING_CHAT),
        ));
        delay(self::TYPING_INTERVAL * 2);
        $this->assertGreaterThan(0, $this->typingCallCount());

        $workerChannel->close();

        $before = $this->typingCallCount();
        delay(self::TYPING_INTERVAL * 4);
        return $this->typingCallCount() - $before;
    }

    private function draftCallCount(): int
    {
        return count(array_filter(
            $this->recordedCalls(),
            fn (array $call): bool => $this->isDraftAction($call['action']) && $call['chatId'] === self::DRAFT_CHAT,
        ));
    }

    private function typingCallCount(): int
    {
        return count(array_filter(
            $this->recordedCalls(),
            fn (array $call): bool => $call['action'] === 'sendChatAction' && $call['chatId'] === self::TYPING_CHAT,
        ));
    }

    private function isDraftAction(string $action): bool
    {
        return $action === 'sendMessageDraft' || $action === 'sendRichMessageDraft';
    }

    /** @return array{0: \Amp\Sync\Channel, 1: \Amp\Sync\Channel} */
    private function wireTracker(): array
    {
        [$workerChannel, $mainChannel] = IntegrationTestDsl::createChannelPair();

        $finalMessageTracker = new FinalMessageTracker();
        $chatActionUpdater = new ChatActionUpdater($finalMessageTracker, self::TYPING_INTERVAL, logger: new \Psr\Log\NullLogger());
        // Throttling is exercised by DraftThrottleIntegrationTest; disable it here so
        // every refresh actually reaches Telegram and the post-close leak is visible.
        $draftUpdater = new DraftUpdater($finalMessageTracker, self::REFRESH, maxSendsPerWindow: 1000, logger: new \Psr\Log\NullLogger());
        $runningTaskTracker = new RunningTaskTracker($chatActionUpdater, $draftUpdater, $finalMessageTracker, logger: new \Psr\Log\NullLogger());

        $execution = IntegrationTestDsl::makeExecution($mainChannel);
        async(static fn () => $runningTaskTracker->receive($execution));

        return [$workerChannel, $mainChannel];
    }
}
