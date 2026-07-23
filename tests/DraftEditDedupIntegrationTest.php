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
 * Verifies that edits whose text has not changed are not re-pushed to Telegram.
 *
 * While a group-chat response streams into an existing message, the worker keeps
 * emitting draft updates even when the content is frozen (e.g. during a slow
 * tool call that only changes the status line). Re-pushing identical text makes
 * Telegram answer with 400 "message is not modified", which used to spam the
 * logs at ERROR level. DraftUpdater now remembers the last accepted edit text
 * per worker and skips identical edits.
 */
#[CoversClass(DraftUpdater::class)]
class DraftEditDedupIntegrationTest extends TestCase
{
    use TelegramRecordingTrait;

    protected function setUp(): void
    {
        $this->installRecordingTelegramClient();
    }

    public function testIdenticalEditIsNotResent(): void
    {
        ob_start();
        try {
            $editTexts = async(fn () => $this->runIdenticalEditScenario())->await();
        } finally {
            ob_end_clean();
        }

        $this->assertSame(
            ['hello', 'hello world'],
            $editTexts,
            'Only edits whose text actually changed may reach Telegram',
        );
    }

    public function testNotModifiedErrorIsHandledGracefully(): void
    {
        // Force every editMessageText call to look like Telegram's
        // "message is not modified" rejection, as happened in production.
        $this->telegramResponseOverride = static function (string $action): ?array {
            if ($action === 'editMessageText') {
                return [
                    'ok' => false,
                    'error_code' => 400,
                    'description' => 'Bad Request: message is not modified: specified new message content and reply markup are exactly the same as a current content and reply markup of the message',
                ];
            }

            return null;
        };

        ob_start();
        try {
            $editCount = async(fn () => $this->runNotModifiedScenario())->await();
        } finally {
            ob_end_clean();
        }

        // The first identical edit hits Telegram once (and is rejected); the
        // rejection is recorded as the last sent text, so the following
        // identical edits are skipped instead of re-triggering the error.
        $this->assertSame(1, $editCount, 'Identical edits after a "not modified" rejection must not be retried');
    }

    /** @return list<string> */
    private function runIdenticalEditScenario(): array
    {
        [$workerChannel] = $this->wireTracker();

        $this->sendEdit($workerChannel, 'hello');
        delay(0.15);
        // Same content again, as a frozen stream would emit.
        $this->sendEdit($workerChannel, 'hello');
        delay(0.15);
        $this->sendEdit($workerChannel, 'hello');
        delay(0.15);
        // Genuinely new content must still be delivered.
        $this->sendEdit($workerChannel, 'hello world');
        delay(0.15);

        $workerChannel->send(new TaskCompletedMessage(1));
        delay(0.2);
        $workerChannel->close();

        return array_column($this->editCalls(), 'text');
    }

    private function runNotModifiedScenario(): int
    {
        [$workerChannel] = $this->wireTracker();

        $this->sendEdit($workerChannel, 'frozen');
        delay(0.15);
        // Two more identical pushes that must be skipped after the rejection.
        $this->sendEdit($workerChannel, 'frozen');
        delay(0.15);
        $this->sendEdit($workerChannel, 'frozen');
        delay(0.15);

        $workerChannel->send(new TaskCompletedMessage(1));
        delay(0.2);
        $workerChannel->close();

        return count($this->editCalls());
    }

    private function sendEdit(\Amp\Sync\Channel $workerChannel, string $text): void
    {
        $workerChannel->send(new DraftUpdateMessage(
            1,
            new DraftState(chatId: -600, draftId: null, text: $text, parseMode: 'Default', editMessageId: 42),
        ));
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
