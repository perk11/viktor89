<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

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
 * Reproduces the "draft appears after the final message" bug as it manifests in
 * consumers (e.g. Jellybot) that build MessageChainProcessorRunner with a
 * ProcessingResultExecutor that has NO beforeMessageSentNotifier.
 *
 * The assistant streams a draft (so the main process has a live draft + refresh
 * timer for the worker). The runner then executes the streamed result. Without a
 * handshake before that send, the main process is never told to stop the draft,
 * so the refresh timer (and any draft updates still in the IPC pipe) fire after
 * the final message — exactly the deterministic, every-time symptom.
 *
 * The fix is for the runner itself to perform the pre-send handshake via the
 * ProgressUpdateCallback (which always carries the worker channel), so that the
 * draft is stopped regardless of how the executor was constructed.
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

    public function testRunnerStopsDraftBeforeFinalMessageEvenWithoutExecutorNotifier(): void
    {
        ob_start();
        try {
            $actionsAfterMessage = async(fn () => $this->runScenario())->await();
        } finally {
            ob_end_clean();
        }

        $this->assertNotSame([], $this->recordedActions(), 'Some Telegram calls must have been recorded');
        $this->assertContains('sendRichMessageDraft', $this->recordedActions(), 'A draft must have been sent while streaming');
        $this->assertContains('sendRichMessage', $this->recordedActions(), 'A final message must have been sent');
        $this->assertNotContains('sendRichMessageDraft', $actionsAfterMessage, 'Draft must not be sent/refreshed after the final message');
        $this->assertNotContains('sendMessageDraft', $actionsAfterMessage, 'Draft must not be sent/refreshed after the final message');
        $this->assertNotContains('sendChatAction', $actionsAfterMessage, 'Typing must not be sent after the final message');
    }

    /** @return list<string> */
    private function runScenario(): array
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

        // NOTE: executor built WITHOUT a beforeMessageSentNotifier — exactly how
        // Jellybot (and any consumer that forgets the handshake) wires it.
        $executor = new ProcessingResultExecutor(new \Perk11\Viktor89\Test\Support\NullMessageRepository(), true);
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
