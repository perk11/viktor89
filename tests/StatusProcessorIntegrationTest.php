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
                static fn (array $call): bool => in_array($call['action'], ['sendMessage', 'sendRichMessage'], true),
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

    public function testStatusTruncatesLongTaskStatus(): void
    {
        $longStatus = str_repeat('а', 250);
        $sentText = $this->runScenario(registerTask: true, status: $longStatus);

        $expected = str_repeat('а', 200) . '…';
        $this->assertStringContainsString($expected, $sentText, 'Long status must be truncated to 200 characters');
        $this->assertStringNotContainsString(str_repeat('а', 201), $sentText, 'Status must not exceed 200 characters');
    }

    public function testStatusCollapsesNewlinesInTaskStatus(): void
    {
        // A newline inside a markdown table cell breaks the whole table, so
        // embedded whitespace in a status must be collapsed to single spaces.
        $status = "Generating image for prompt: аниме стиль\n\n🎬 Сцена: описание сцены";
        $sentText = $this->runScenario(registerTask: true, status: $status);

        $this->assertStringContainsString('аниме стиль 🎬 Сцена: описание сцены', $sentText, 'Newlines in status must be collapsed to spaces');
        $this->assertStringNotContainsString("аниме стиль\n\n🎬 Сцена", $sentText, 'Status must not contain raw newlines');
        $this->assertStringNotContainsString("аниме стиль\n🎬 Сцена", $sentText);
    }

    private function runScenario(bool $registerTask, string $status = 'Transcribing audio message'): string
    {
        ob_start();
        try {
            return async(fn () => $this->runScenarioAsync($registerTask, $status))->await();
        } finally {
            ob_end_clean();
        }
    }

    private function runScenarioAsync(bool $registerTask, string $status): string
    {
        [$workerChannel, $mainChannel] = IntegrationTestDsl::createChannelPair();

        $finalMessageTracker = new FinalMessageTracker();
        $chatActionUpdater = new ChatActionUpdater($finalMessageTracker, 999, logger: new \Psr\Log\NullLogger());
        $draftUpdater = new DraftUpdater($finalMessageTracker, 999, logger: new \Psr\Log\NullLogger());
        $runningTaskTracker = new RunningTaskTracker($chatActionUpdater, $draftUpdater, $finalMessageTracker, logger: new \Psr\Log\NullLogger());

        $execution = IntegrationTestDsl::makeExecution($mainChannel);
        $mainFuture = async(static fn () => $runningTaskTracker->receive($execution));

        if ($registerTask) {
            $workerChannel->send(new TaskUpdateMessage(
                1,
                'Perk11\Viktor89\TranscribingAssistant',
                $status,
                IntegrationTestDsl::typingAction(555),
            ));
            delay(0.05);
        }

        $statusProcessor = new StatusProcessor($workerChannel);
        $result = $statusProcessor->processMessageChain(
            IntegrationTestDsl::buildIncomingMessageChain(555, '/status'),
            new EchoUpdateCallback(logger: new \Psr\Log\NullLogger()),
        );

        (new ProcessingResultExecutor(new \Perk11\Viktor89\Test\Support\NullMessageRepository(), logger: new \Psr\Log\NullLogger()))->execute($result);

        if ($registerTask) {
            $workerChannel->send(new TaskCompletedMessage(1));
        }
        delay(0.05);
        $workerChannel->close();
        $mainFuture->await();

        return $result->response->messageText;
    }
}
