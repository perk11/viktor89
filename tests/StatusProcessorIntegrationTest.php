<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Database;
use Perk11\Viktor89\IPC\ChatActionUpdater;
use Perk11\Viktor89\IPC\DraftUpdater;
use Perk11\Viktor89\IPC\EchoUpdateCallback;
use Perk11\Viktor89\IPC\FinalMessageTracker;
use Perk11\Viktor89\IPC\RunningTaskTracker;
use Perk11\Viktor89\IPC\StatusProcessor;
use Perk11\Viktor89\IPC\TaskCompletedMessage;
use Perk11\Viktor89\IPC\TaskUpdateMessage;
use Perk11\Viktor89\ProcessingResultExecutor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Perk11\Viktor89\Test\Support\IntegrationTestDsl;
use Perk11\Viktor89\Test\Support\TelegramRecordingTrait;

use function Amp\async;
use function Amp\delay;

require_once __DIR__ . '/Support/IntegrationTestSupport.php';

/**
 * Verifies the worker<->main handshake that powers the /status command: a
 * StatusProcessor running in the worker sends a RunningTasksQueryMessage over
 * the channel, the main RunningTaskTracker replies with a RunningTasksReport
 * built from the tasks it currently knows about, and StatusProcessor renders
 * that into the message that is sent to Telegram.
 */
#[CoversClass(StatusProcessor::class)]
#[CoversClass(RunningTaskTracker::class)]
class StatusProcessorIntegrationTest extends TestCase
{
    use TelegramRecordingTrait;

    protected function setUp(): void
    {
        $this->installRecordingTelegramClient();
    }

    public function testStatusReportsRegisteredTasks(): void
    {
        $sentText = $this->runScenario(registerTask: true);

        $this->assertNotEmpty(
            array_filter(
                $this->recordedCalls(),
                static fn (array $call): bool => $call['action'] === 'sendMessage',
            ),
            'The status report should have been sent to Telegram',
        );
        $this->assertStringContainsString('TranscribingAssistant', $sentText, 'Report must list the processor');
        $this->assertStringContainsString('Transcribing audio message', $sentText, 'Report must list the task status');
    }

    public function testStatusReportsNothingWhenNoTasksAreRunning(): void
    {
        $sentText = $this->runScenario(registerTask: false);

        $this->assertStringContainsString('Ничего не происходит', $sentText);
    }

    private function runScenario(bool $registerTask): string
    {
        ob_start();
        try {
            return async(fn () => $this->runScenarioAsync($registerTask))->await();
        } finally {
            ob_end_clean();
        }
    }

    private function runScenarioAsync(bool $registerTask): string
    {
        [$workerChannel, $mainChannel] = IntegrationTestDsl::createChannelPair();

        $finalMessageTracker = new FinalMessageTracker();
        $chatActionUpdater = new ChatActionUpdater($finalMessageTracker, 999);
        $draftUpdater = new DraftUpdater($finalMessageTracker, 999);
        $runningTaskTracker = new RunningTaskTracker($chatActionUpdater, $draftUpdater, $finalMessageTracker);

        $execution = IntegrationTestDsl::makeExecution($mainChannel);
        $mainFuture = async(static fn () => $runningTaskTracker->receive($execution));

        if ($registerTask) {
            $workerChannel->send(new TaskUpdateMessage(
                1,
                'Perk11\Viktor89\TranscribingAssistant',
                'Transcribing audio message',
                IntegrationTestDsl::typingAction(555),
            ));
            delay(0.05);
        }

        $statusProcessor = new StatusProcessor($workerChannel);
        $result = $statusProcessor->processMessageChain(
            IntegrationTestDsl::buildIncomingMessageChain(555, '/status'),
            new EchoUpdateCallback(),
        );

        (new ProcessingResultExecutor(new \Perk11\Viktor89\Test\Support\NullMessageRepository()))->execute($result);

        if ($registerTask) {
            $workerChannel->send(new TaskCompletedMessage(1));
        }
        delay(0.05);
        $workerChannel->close();
        $mainFuture->await();

        return $result->response->messageText;
    }
}
