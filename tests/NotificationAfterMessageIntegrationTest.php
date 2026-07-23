<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Database;
use Perk11\Viktor89\IPC\ChannelBeforeMessageSentNotifier;
use Perk11\Viktor89\IPC\ChannelDraftUpdateCallback;
use Perk11\Viktor89\IPC\ChatActionUpdater;
use Perk11\Viktor89\IPC\DraftUpdater;
use Perk11\Viktor89\IPC\EngineProgressUpdateCallback;
use Perk11\Viktor89\IPC\FinalMessageTracker;
use Perk11\Viktor89\IPC\RunningTaskTracker;
use Perk11\Viktor89\IPC\TaskCompletedMessage;
use Perk11\Viktor89\ProcessingResultExecutor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Perk11\Viktor89\Test\Support\IntegrationTestDsl;
use Perk11\Viktor89\Test\Support\StubStreamingAssistant;
use Perk11\Viktor89\Test\Support\TelegramRecordingTrait;

use function Amp\async;
use function Amp\delay;

require_once __DIR__ . '/Support/IntegrationTestSupport.php';

/**
 * Integration test reproducing the "typing / draft is sent after the actual
 * message" race condition.
 *
 * Everything runs for real (ChatActionUpdater, DraftUpdater, RunningTaskTracker,
 * FinalMessageTracker, ProcessingResultExecutor, the worker<->main IPC channel,
 * the event loop and its timers). Only Telegram (via Request::setClient) and the
 * LLM (via a stub assistant) are mocked.
 */
#[CoversClass(ChatActionUpdater::class)]
#[CoversClass(DraftUpdater::class)]
#[CoversClass(FinalMessageTracker::class)]
#[CoversClass(RunningTaskTracker::class)]
#[CoversClass(ProcessingResultExecutor::class)]
class NotificationAfterMessageIntegrationTest extends TestCase
{
    use TelegramRecordingTrait;

    private const float TYPING_INTERVAL = 0.05;
    private const float DRAFT_REFRESH_INTERVAL = 0.1;
    private const float POST_MESSAGE_OBSERVATION_WINDOW = 0.4;

    protected function setUp(): void
    {
        $this->installRecordingTelegramClient();
    }

    /**
     * Private chat (chat id > 0): the assistant uses drafts. This reproduces the
     * issue for both typing notifications and draft refreshes.
     */
    public function testDraftAndTypingAreNotSentAfterFinalMessageInPrivateChat(): void
    {
        $actions = $this->runScenario(chatId: 11111);

        $this->assertContains('sendChatAction', $actions, 'Typing notification should have been sent while generating');
        $this->assertContains('sendRichMessageDraft', $actions, 'A draft should have been sent while streaming');
        $this->assertContains('sendRichMessage', $actions, 'A final message should have been sent');

        $actionsAfterMessage = $this->recordedActionsAfterLastMessage();

        $this->assertNotSame([], $this->recordedActions(), 'Some Telegram calls must have been recorded');
        $this->assertNotContains('sendChatAction', $actionsAfterMessage, 'Typing must not be sent after the final message');
        $this->assertNotContains('sendRichMessageDraft', $actionsAfterMessage, 'Draft must not be refreshed after the final message');
        $this->assertNotContains('sendMessageDraft', $actionsAfterMessage, 'Draft must not be refreshed after the final message');
    }

    /**
     * Group chat (chat id < 0): the assistant uses edit-streaming (no drafts).
     * This isolates the typing notification.
     */
    public function testTypingIsNotSentAfterFinalMessageInGroupChat(): void
    {
        $actions = $this->runScenario(chatId: -100200);

        $this->assertContains('sendChatAction', $actions, 'Typing notification should have been sent while generating');

        $actionsAfterMessage = $this->recordedActionsAfterLastMessage();

        $this->assertNotContains('sendChatAction', $actionsAfterMessage, 'Typing must not be sent after the final message');
        $this->assertNotContains('sendRichMessageDraft', $actionsAfterMessage, 'No draft should be involved in a group chat');
    }

    /** @return list<string> */
    private function runScenario(int $chatId): array
    {
        ob_start();
        try {
            return async(fn () => $this->runScenarioAsync($chatId))->await();
        } finally {
            ob_end_clean();
        }
    }

    private function runScenarioAsync(int $chatId): array
    {
        [$workerChannel, $mainChannel] = IntegrationTestDsl::createChannelPair();

        $finalMessageTracker = new FinalMessageTracker();
        $chatActionUpdater = new ChatActionUpdater($finalMessageTracker, self::TYPING_INTERVAL, logger: new \Psr\Log\NullLogger());
        $draftUpdater = new DraftUpdater($finalMessageTracker, self::DRAFT_REFRESH_INTERVAL, logger: new \Psr\Log\NullLogger());
        $runningTaskTracker = new RunningTaskTracker($chatActionUpdater, $draftUpdater, $finalMessageTracker, logger: new \Psr\Log\NullLogger());

        $execution = IntegrationTestDsl::makeExecution($mainChannel);
        $mainFuture = async(static fn () => $runningTaskTracker->receive($execution));

        $callback = new EngineProgressUpdateCallback($workerChannel, 1);
        $assistant = new StubStreamingAssistant(
            IntegrationTestDsl::stubPreferenceReader('You are a helpful test assistant.'),
            IntegrationTestDsl::stubPreferenceReader(null),
            IntegrationTestDsl::stubPreferenceReader('5'),
            new \Perk11\Viktor89\Test\Support\NullTelegramFileDownloader(),
            new \Perk11\Viktor89\Test\Support\NullAltTextProvider(),
            $this->defaultStreamBehavior(),
        );
        $chain = IntegrationTestDsl::buildIncomingMessageChain($chatId);
        $assistant->setDraftUpdateCallback(new ChannelDraftUpdateCallback($workerChannel, 1));

        $result = $assistant->processMessageChain($chain, $callback);

        $executor = new ProcessingResultExecutor(
            new \Perk11\Viktor89\Test\Support\NullMessageRepository(),
            true,
            new ChannelBeforeMessageSentNotifier($workerChannel, 1),
         logger: new \Psr\Log\NullLogger());
        $executor->execute($result);

        delay(self::POST_MESSAGE_OBSERVATION_WINDOW);

        $workerChannel->send(new TaskCompletedMessage(1));
        delay(self::TYPING_INTERVAL * 2);
        $workerChannel->close();
        $mainFuture->await();

        return $this->recordedActions();
    }

    /** Streams a canned response with deliberate pauses so timers get to fire. */
    private function defaultStreamBehavior(): \Closure
    {
        return function ($streamFunction): string {
            delay(0.15);
            $streamFunction('');
            delay(0.15);
            $streamFunction('Hello, ');
            delay(0.15);
            $streamFunction('this is a streamed response.');
            delay(0.15);

            return 'Hello, this is a streamed response.';
        };
    }
}
