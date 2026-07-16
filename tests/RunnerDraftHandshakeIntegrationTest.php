<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\IPC\ChannelBeforeMessageSentNotifier;
use Perk11\Viktor89\IPC\ChannelDraftUpdateCallback;
use Perk11\Viktor89\IPC\ChatActionUpdater;
use Perk11\Viktor89\IPC\DraftUpdater;
use Perk11\Viktor89\IPC\EngineProgressUpdateCallback;
use Perk11\Viktor89\IPC\FinalMessageTracker;
use Perk11\Viktor89\IPC\RunningTaskTracker;
use Perk11\Viktor89\IPC\TaskCompletedMessage;
use Perk11\Viktor89\MessageChainProcessorRunner;
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
 * The pre-send "stop drafts/typing" handshake is performed inside
 * ProcessingResultExecutor::execute() via the BeforeMessageSentNotifier it is
 * constructed with (which carries the worker's IPC channel). This keeps a
 * streamed response from ever leaving a draft behind that lands after the final
 * message — regardless of how the runner / processors are assembled.
 *
 * Contract for any worker ProcessMessageTask: pass a
 * ChannelBeforeMessageSentNotifier to the ProcessingResultExecutor. The two
 * tests below pin both halves of that: with the notifier no draft follows the
 * message; without it the original bug reproduces.
 */
#[CoversClass(MessageChainProcessorRunner::class)]
#[CoversClass(ProcessingResultExecutor::class)]
class RunnerDraftHandshakeIntegrationTest extends TestCase
{
    use TelegramRecordingTrait;

    private const float TYPING_INTERVAL = 0.05;
    private const float DRAFT_REFRESH = 0.1;

    protected function setUp(): void
    {
        $this->installRecordingTelegramClient();
    }

    public function testExecutorStopsDraftBeforeFinalMessageViaNotifier(): void
    {
        ob_start();
        try {
            $actionsAfterMessage = async(fn () => $this->runScenario(withNotifier: true))->await();
        } finally {
            ob_end_clean();
        }

        $this->assertContains('sendRichMessageDraft', $this->recordedActions(), 'A draft must have been sent while streaming');
        $this->assertContains('sendRichMessage', $this->recordedActions(), 'A final message must have been sent');
        $this->assertNotContains('sendRichMessageDraft', $actionsAfterMessage, 'Draft must not be sent/refreshed after the final message');
        $this->assertNotContains('sendMessageDraft', $actionsAfterMessage, 'Draft must not be sent/refreshed after the final message');
        $this->assertNotContains('sendChatAction', $actionsAfterMessage, 'Typing must not be sent after the final message');
    }

    public function testExecutorWithoutNotifierLeaksDraftAfterMessage(): void
    {
        ob_start();
        try {
            $actionsAfterMessage = async(fn () => $this->runScenario(withNotifier: false))->await();
        } finally {
            ob_end_clean();
        }

        // Without the notifier, execute() cannot run the handshake, so the draft
        // refresh timer (and any draft updates still in the IPC pipe) fire after
        // the final message — the original, every-time symptom.
        $this->assertContains(
            'sendRichMessageDraft',
            $actionsAfterMessage,
            'A worker executor built without its ProgressUpdateCallback leaks a draft after the message',
        );
    }

    /** @return list<string> */
    private function runScenario(bool $withNotifier): array
    {
        [$workerChannel, $mainChannel] = IntegrationTestDsl::createChannelPair();

        $finalMessageTracker = new FinalMessageTracker();
        $chatActionUpdater = new ChatActionUpdater($finalMessageTracker, self::TYPING_INTERVAL);
        $draftUpdater = new DraftUpdater($finalMessageTracker, self::DRAFT_REFRESH, maxSendsPerWindow: 1000);
        $runningTaskTracker = new RunningTaskTracker($chatActionUpdater, $draftUpdater, $finalMessageTracker);

        $execution = IntegrationTestDsl::makeExecution($mainChannel);
        async(static fn () => $runningTaskTracker->receive($execution));

        $callback = new EngineProgressUpdateCallback($workerChannel, 1);
        $assistant = new StubStreamingAssistant(
            IntegrationTestDsl::stubPreferenceReader('You are a helpful test assistant.'),
            IntegrationTestDsl::stubPreferenceReader(null),
            IntegrationTestDsl::stubPreferenceReader(null),
            new \Perk11\Viktor89\Test\Support\NullTelegramFileDownloader(),
            new \Perk11\Viktor89\Test\Support\NullAltTextProvider(),
            $this->streamBehavior(),
        );
        $chain = IntegrationTestDsl::buildIncomingMessageChain(11111); // private chat -> drafts
        $assistant->setDraftUpdateCallback(new ChannelDraftUpdateCallback($workerChannel, 1));

        $executor = new ProcessingResultExecutor(
            new \Perk11\Viktor89\Test\Support\NullMessageRepository(),
            true,
            $withNotifier ? new ChannelBeforeMessageSentNotifier($workerChannel, 1) : null,
        );
        $runner = new MessageChainProcessorRunner($executor, [$assistant]);
        $runner->run($chain, $callback);

        // Let any leaked refresh timer / queued draft fire after the message.
        delay(self::DRAFT_REFRESH * 4);

        $workerChannel->send(new TaskCompletedMessage(1));
        delay(self::TYPING_INTERVAL * 2);
        $workerChannel->close();

        return $this->recordedActionsAfterLastMessage();
    }

    private function streamBehavior(): \Closure
    {
        return function ($streamFunction): string {
            delay(0.05);
            $streamFunction('');
            delay(0.05);
            $streamFunction('Hello, ');
            delay(0.05);
            $streamFunction('this is a streamed response.');

            return 'Hello, this is a streamed response.';
        };
    }
}
