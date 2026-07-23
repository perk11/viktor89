<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\IPC\DraftState;
use Perk11\Viktor89\IPC\DraftUpdater;
use Perk11\Viktor89\IPC\FinalMessageTracker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Perk11\Viktor89\Test\Support\TelegramRecordingTrait;

require_once __DIR__ . '/Support/IntegrationTestSupport.php';

/**
 * deliverAndRemoveDraft() runs when a worker finishes without sending a final
 * message (abort, reaction-only, exception). It used to unconditionally re-send
 * the last streamed edit, which Telegram rejects as "400 message is not
 * modified" because that content was already pushed by the last updateDraft().
 * It must now flush only when a throttled flush is still pending for the chat.
 */
#[CoversClass(DraftUpdater::class)]
class DraftFinalFlushIntegrationTest extends TestCase
{
    use TelegramRecordingTrait;

    private const int CHAT = -1002398016894;

    protected function setUp(): void
    {
        $this->installRecordingTelegramClient();
    }

    public function testDoesNotReEditWhenContentAlreadyDelivered(): void
    {
        $updater = new DraftUpdater(new FinalMessageTracker(), logger: new \Psr\Log\NullLogger());

        ob_start();
        try {
            $updater->updateDraft(4, new DraftState(self::CHAT, null, 'hello', 'Default', editMessageId: 12202));
            $updater->deliverAndRemoveDraft(4);
        } finally {
            ob_end_clean();
        }

        $edits = $this->editCalls();
        $this->assertCount(1, $edits, 'Completion must not re-edit a message whose content was already delivered');
        $this->assertSame('hello', $edits[0]['text']);
    }

    public function testFlushesWhenAThrottledFlushIsStillPending(): void
    {
        // maxSendsPerWindow = 1 so the second update is deferred (a flush is
        // scheduled) instead of sent immediately.
        $updater = new DraftUpdater(new FinalMessageTracker(), maxSendsPerWindow: 1, logger: new \Psr\Log\NullLogger());

        ob_start();
        try {
            $updater->updateDraft(5, new DraftState(self::CHAT, null, 'a', 'Default', editMessageId: 12203));
            $updater->updateDraft(5, new DraftState(self::CHAT, null, 'ab', 'Default', editMessageId: 12203));
            $this->assertCount(1, $this->editCalls(), 'second update should have been deferred, not sent');
            $updater->deliverAndRemoveDraft(5);
        } finally {
            ob_end_clean();
        }

        $edits = $this->editCalls();
        $this->assertCount(2, $edits, 'Pending throttled content must still be flushed on completion');
        $this->assertSame('ab', $edits[1]['text']);
    }

    /** @return list<array{action: string, text: ?string}> */
    private function editCalls(): array
    {
        return array_values(array_filter(
            $this->recordedCalls(),
            static fn(array $call): bool => $call['action'] === 'editMessageText',
        ));
    }
}
