<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AbstractOpenAIAPiAssistant;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\IPC\ChannelDraftUpdateCallback;
use Perk11\Viktor89\IPC\ChatActionUpdater;
use Perk11\Viktor89\IPC\DraftUpdater;
use Perk11\Viktor89\IPC\EngineProgressUpdateCallback;
use Perk11\Viktor89\IPC\FinalMessageTracker;
use Perk11\Viktor89\IPC\RunningTaskTracker;
use Perk11\Viktor89\IPC\TaskCompletedMessage;
use Perk11\Viktor89\ProcessingResultExecutor;
use Perk11\Viktor89\ProcessMessageTask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Perk11\Viktor89\Test\Support\IntegrationTestDsl;
use Perk11\Viktor89\Test\Support\StubStreamingAssistant;
use Perk11\Viktor89\Test\Support\TelegramRecordingTrait;

use function Amp\async;
use function Amp\delay;

require_once __DIR__ . '/Support/IntegrationTestSupport.php';

/**
 * Exercises the group-chat (edit-stream) response path end to end: the first
 * streamed chunk creates the message, a later large chunk edits it in place,
 * and — crucially — the typing notification is stopped before the final edit
 * so that no typing can appear after the message.
 *
 * In a group chat the assistant does not use drafts; instead it sends a message
 * and then edits it as more content streams in.
 */
#[CoversClass(ChatActionUpdater::class)]
#[CoversClass(RunningTaskTracker::class)]
#[CoversClass(ProcessingResultExecutor::class)]
#[CoversClass(AbstractOpenAIAPiAssistant::class)]
class GroupChatEditStreamingIntegrationTest extends TestCase
{
    use TelegramRecordingTrait;

    private const float TYPING_INTERVAL = 0.1;

    protected function setUp(): void
    {
        $this->installRecordingTelegramClient();
    }

    public function testGroupChatMessageIsCreatedThenEditedAndTypingStopsBeforeFinalEdit(): void
    {
        ob_start();
        try {
            $actions = async(fn () => $this->runScenario())->await();
        } finally {
            ob_end_clean();
        }

        $this->assertContains('sendChatAction', $actions, 'Typing should be active while generating');
        $this->assertContains('sendRichMessage', $actions, 'The first chunk should create the message');
        $this->assertContains('editMessageText', $actions, 'A later chunk should edit the message in place');

        $actionsAfterMessage = $this->recordedActionsAfterLastMessage();
        $this->assertNotContains(
            'sendChatAction',
            $actionsAfterMessage,
            'Typing must not be sent after the final edit',
        );
    }

    /** @return list<string> */
    private function runScenario(): array
    {
        [$workerChannel, $mainChannel] = IntegrationTestDsl::createChannelPair();

        $finalMessageTracker = new FinalMessageTracker();
        $chatActionUpdater = new ChatActionUpdater($finalMessageTracker, self::TYPING_INTERVAL);
        $draftUpdater = new DraftUpdater($finalMessageTracker, 999);
        $runningTaskTracker = new RunningTaskTracker($chatActionUpdater, $draftUpdater, $finalMessageTracker);

        $execution = IntegrationTestDsl::makeExecution($mainChannel);
        $mainFuture = async(static fn () => $runningTaskTracker->receive($execution));

        // null edit-frequency preference => the assistant uses its minimum
        // (EDIT_FREQUENCY_MIN_SECONDS = 1.5s), so edits can happen quickly in the test.
        $callback = new EngineProgressUpdateCallback($workerChannel, 1);
        $assistant = new StubStreamingAssistant(
            IntegrationTestDsl::stubPreferenceReader('You are a helpful test assistant.'),
            IntegrationTestDsl::stubPreferenceReader(null),
            IntegrationTestDsl::stubPreferenceReader(null),
            new \Perk11\Viktor89\Test\Support\NullTelegramFileDownloader(),
            new \Perk11\Viktor89\Test\Support\NullAltTextProvider(),
            $this->editStreamBehavior(),
        );
        $chain = IntegrationTestDsl::buildIncomingMessageChain(-100300);
        $assistant->setDraftUpdateCallback(new ChannelDraftUpdateCallback($workerChannel, 1));

        $result = $assistant->processMessageChain($chain, $callback);

        $executor = new ProcessingResultExecutor(
            new \Perk11\Viktor89\Test\Support\NullDatabase(),
            true,
            ProcessMessageTask::createBeforeMessageSentNotifier($workerChannel, 1, $callback),
        );
        $executor->execute($result);

        delay(0.3);

        $workerChannel->send(new TaskCompletedMessage(1));
        delay(self::TYPING_INTERVAL * 2);
        $workerChannel->close();
        $mainFuture->await();

        return $this->recordedActions();
    }

    /**
     * First chunk (>=10 chars) creates the message; after the edit frequency
     * elapses, a large chunk (>=64 chars) edits it. Returns the full content.
     */
    private function editStreamBehavior(): \Closure
    {
        return function ($streamFunction): string {
            delay(0.2);
            $first = 'This is the first chunk of the streamed response.';
            $streamFunction($first); // creates the message (sendRichMessage)

            delay(1.6); // >= edit frequency minimum (1.5s)

            $second = str_repeat('more content here ', 9); // >=64 chars, so not throttled
            $streamFunction($second); // edits the message (editMessageText)

            return $first . $second;
        };
    }
}
