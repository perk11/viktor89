<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Amp\Sync\Channel;
use Perk11\Viktor89\IPC\AckMessage;
use Perk11\Viktor89\IPC\ChatActionUpdater;
use Perk11\Viktor89\IPC\DraftState;
use Perk11\Viktor89\IPC\DraftUpdateMessage;
use Perk11\Viktor89\IPC\DraftUpdater;
use Perk11\Viktor89\IPC\FinalMessageTracker;
use Perk11\Viktor89\IPC\MessageAboutToBeSentMessage;
use Perk11\Viktor89\IPC\RunningTaskTracker;
use Perk11\Viktor89\IPC\TaskCompletedMessage;
use Perk11\Viktor89\IPC\TaskUpdateMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Perk11\Viktor89\Test\Support\IntegrationTestDsl;
use Perk11\Viktor89\Test\Support\TelegramRecordingTrait;

use function Amp\async;
use function Amp\delay;

require_once __DIR__ . '/Support/IntegrationTestSupport.php';

/**
 * Verifies that typing notifications and drafts are tracked per worker / per
 * chat, so that finalising or completing one worker never disturbs another
 * worker's notifications — including the shared per-chat timer that survives
 * until the last worker in a chat is done.
 */
#[CoversClass(ChatActionUpdater::class)]
#[CoversClass(DraftUpdater::class)]
#[CoversClass(FinalMessageTracker::class)]
#[CoversClass(RunningTaskTracker::class)]
class ConcurrentWorkersIntegrationTest extends TestCase
{
    use TelegramRecordingTrait;

    private const float INTERVAL = 0.06;

    protected function setUp(): void
    {
        $this->installRecordingTelegramClient();
    }

    public function testFinalisingOneWorkerLeavesAnotherUnaffected(): void
    {
        ob_start();
        try {
            [$chatA, $chatB, $windowCalls] = async(fn () => $this->runTwoChatScenario())->await();
        } finally {
            ob_end_clean();
        }

        $callsInChatA = $this->notificationCallsForChat($windowCalls, $chatA);
        $callsInChatB = $this->notificationCallsForChat($windowCalls, $chatB);

        $this->assertSame(
            [],
            $callsInChatA,
            'Worker 1 was finalised, so no typing/draft may be sent to its chat afterwards',
        );
        $this->assertNotEmpty(
            $callsInChatB,
            "Worker 2 is still running, so its typing/draft must keep being sent to its chat",
        );
    }

    public function testSharedPerChatTimerSurvivesUntilLastWorkerFinishes(): void
    {
        ob_start();
        try {
            [$window1Calls, $window2Calls] = async(fn () => $this->runSharedChatScenario())->await();
        } finally {
            ob_end_clean();
        }

        $this->assertNotEmpty(
            $this->notificationCallsForChat($window1Calls, 300),
            'After finalising only one of two workers in a chat, the shared timer must keep firing for the other',
        );
        $this->assertSame(
            [],
            $this->notificationCallsForChat($window2Calls, 300),
            'After the last worker in a chat finishes, the shared timer must be cancelled',
        );
    }

    /** @return array{0: int, 1: int, 2: list<array<string, mixed>>} */
    private function runTwoChatScenario(): array
    {
        $chatA = 100;
        $chatB = 200;
        [$workerChannel] = $this->wireTracker();

        $this->startWorker($workerChannel, workerId: 1, chatId: $chatA, draftId: 501, draftText: 'A draft');
        $this->startWorker($workerChannel, workerId: 2, chatId: $chatB, draftId: 502, draftText: 'B draft');
        delay(self::INTERVAL * 2);

        $this->finalise($workerChannel, workerId: 1, chatId: $chatA);
        $snapshot = count($this->recordedCalls());
        delay(self::INTERVAL * 5);

        $windowCalls = array_slice($this->recordedCalls(), $snapshot);

        $workerChannel->send(new TaskCompletedMessage(1));
        $workerChannel->send(new TaskCompletedMessage(2));
        delay(self::INTERVAL);
        $workerChannel->close();

        return [$chatA, $chatB, $windowCalls];
    }

    /** @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} */
    private function runSharedChatScenario(): array
    {
        $chatC = 300;
        [$workerChannel] = $this->wireTracker();

        $this->startWorker($workerChannel, workerId: 1, chatId: $chatC, draftId: 601, draftText: 'C draft 1');
        $this->startWorker($workerChannel, workerId: 2, chatId: $chatC, draftId: 602, draftText: 'C draft 2');
        delay(self::INTERVAL * 2);

        $this->finalise($workerChannel, workerId: 1, chatId: $chatC);
        $snapshot1 = count($this->recordedCalls());
        delay(self::INTERVAL * 4);
        $window1Calls = array_slice($this->recordedCalls(), $snapshot1);

        $this->finalise($workerChannel, workerId: 2, chatId: $chatC);
        $snapshot2 = count($this->recordedCalls());
        delay(self::INTERVAL * 4);
        $window2Calls = array_slice($this->recordedCalls(), $snapshot2);

        $workerChannel->send(new TaskCompletedMessage(1));
        $workerChannel->send(new TaskCompletedMessage(2));
        delay(self::INTERVAL);
        $workerChannel->close();

        return [$window1Calls, $window2Calls];
    }

    private function startWorker(Channel $workerChannel, int $workerId, int $chatId, int $draftId, string $draftText): void
    {
        $workerChannel->send(new TaskUpdateMessage(
            $workerId,
            'Assistant',
            'thinking',
            IntegrationTestDsl::typingAction($chatId),
        ));
        $workerChannel->send(new DraftUpdateMessage(
            $workerId,
            new DraftState(chatId: $chatId, draftId: $draftId, text: $draftText, parseMode: 'Default'),
        ));
    }

    private function finalise(Channel $workerChannel, int $workerId, int $chatId): void
    {
        $workerChannel->send(new MessageAboutToBeSentMessage($workerId, $chatId));
        $ack = $workerChannel->receive();
        // Receiving the ack synchronises us with the main process having
        // stopped this worker's notifications before we observe the window.
        $this->assertInstanceOf(AckMessage::class, $ack);
    }

    /** @param list<array<string, mixed>> $calls */
    private function notificationCallsForChat(array $calls, int $chatId): array
    {
        return array_values(array_filter(
            $calls,
            fn (array $call): bool => $call['chatId'] === $chatId
                && ($call['action'] === 'sendChatAction'
                    || $call['action'] === 'sendMessageDraft'
                    || $call['action'] === 'sendRichMessageDraft'),
        ));
    }

    /** @return array{0: Channel, 1: Channel} */
    private function wireTracker(): array
    {
        [$workerChannel, $mainChannel] = IntegrationTestDsl::createChannelPair();

        $finalMessageTracker = new FinalMessageTracker();
        $chatActionUpdater = new ChatActionUpdater($finalMessageTracker, self::INTERVAL);
        $draftUpdater = new DraftUpdater($finalMessageTracker, self::INTERVAL);
        $runningTaskTracker = new RunningTaskTracker($chatActionUpdater, $draftUpdater, $finalMessageTracker);

        $execution = IntegrationTestDsl::makeExecution($mainChannel);
        async(static fn () => $runningTaskTracker->receive($execution));

        return [$workerChannel, $mainChannel];
    }
}
