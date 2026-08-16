<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AbstractOpenAIAPiAssistant;
use Perk11\Viktor89\IPC\ChannelBeforeMessageSentNotifier;
use Perk11\Viktor89\IPC\ChannelDraftUpdateCallback;
use Perk11\Viktor89\IPC\ChatActionUpdater;
use Perk11\Viktor89\IPC\DraftUpdater;
use Perk11\Viktor89\IPC\EngineProgressUpdateCallback;
use Perk11\Viktor89\IPC\FinalMessageTracker;
use Perk11\Viktor89\IPC\RunningTaskTracker;
use Perk11\Viktor89\ProcessingResultExecutor;
use Perk11\Viktor89\Test\Support\IntegrationTestDsl;
use Perk11\Viktor89\Test\Support\StubStreamingAssistant;
use Perk11\Viktor89\Test\Support\TelegramRecordingTrait;
use Perk11\Viktor89\Util\TelegramRichMarkdown;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

require_once __DIR__ . '/Support/IntegrationTestSupport.php';

/**
 * When a streamed RichMarkdown response outgrows Telegram's rich message
 * limit, the markdown file may only be sent once generation is done: until
 * then the streamed message (group chat) or draft (private chat) must keep
 * existing, capped off with the trailing "results will be sent as a file"
 * notice.
 */
#[CoversClass(AbstractOpenAIAPiAssistant::class)]
#[CoversClass(DraftUpdater::class)]
class OverlongResponseFileDeliveryIntegrationTest extends TestCase
{
    use TelegramRecordingTrait;

    private const float TYPING_INTERVAL = 0.1;
    private const string NOTICE = 'Max length reached, continuing generation, results will be sent as a file...';

    protected function setUp(): void
    {
        $this->installRecordingTelegramClient();
    }

    /**
     * Group chat via the DraftUpdater-routed edit stream: the over-long chunk
     * only edits the message with the notice; the file is sent (and the
     * streamed message deleted) exclusively by the final edit after
     * generation completes.
     */
    public function testGroupChatFileIsOnlySentAfterGenerationCompletes(): void
    {
        ob_start();
        try {
            $actions = async(fn () => $this->runScenario(-100300, withDraftCallback: true, behavior: $this->overlongStreamBehavior()))->await();
        } finally {
            ob_end_clean();
        }

        $this->assertContains('sendRichMessage', $actions, 'The streamed message must be created');
        $this->assertSame(
            1,
            substr_count(implode(' ', $actions) . ' ', 'sendDocument '),
            'The markdown file must be sent exactly once, by the final edit',
        );

        $noticeEditIndex = null;
        $fileSendIndex = null;
        foreach ($actions as $index => $action) {
            if ($action === 'editMessageText' && $noticeEditIndex === null) {
                $noticeEditIndex = $index;
            }
            if ($action === 'sendDocument') {
                $fileSendIndex = $index;
            }
        }
        $this->assertNotNull($noticeEditIndex, 'the over-long chunk must edit the message with the trailing notice');
        $this->assertNotNull($fileSendIndex);
        $this->assertGreaterThan(
            $noticeEditIndex,
            $fileSendIndex,
            'the file must not be sent while generation is still streaming',
        );

        $this->assertStringEndsWith(self::NOTICE, $this->lastStreamedEditMarkdown());
        $this->assertLessThanOrEqual(
            TelegramRichMarkdown::MAX_LENGTH,
            mb_strlen($this->lastStreamedEditMarkdown()),
            'the notice edit itself must stay within the rich message limit',
        );

        $deletes = $this->callsWithAction('deleteMessage');
        $this->assertCount(1, $deletes, 'the streamed message must be deleted, keeping only the file');
        $this->assertSame(42, (int) $deletes[0]['form']['message_id']);
        $this->assertStringContainsString($this->fullFinalContent(), $this->documentRequestBody());
    }

    /**
     * Same group-chat flow but without the DraftUpdater callback (direct
     * edits): the notice edit must not be turned into the file delivery —
     * regression test for the intermediate edit triggering sendDocument
     * mid-generation.
     */
    public function testGroupChatFallbackEditStreamDelaysFileUntilFinalEdit(): void
    {
        ob_start();
        try {
            $actions = async(fn () => $this->runScenario(-100300, withDraftCallback: false, behavior: $this->overlongStreamBehavior()))->await();
        } finally {
            ob_end_clean();
        }

        $documentIndexes = array_keys($actions, 'sendDocument');
        $noticeEditIndex = array_search('editMessageText', $actions, true);

        $this->assertNotFalse($noticeEditIndex, 'the over-long chunk must edit the message with the notice');
        $this->assertCount(1, $documentIndexes, 'the file must be sent exactly once');
        $this->assertGreaterThan($noticeEditIndex, $documentIndexes[0], 'the file must only be sent after generation completes');
        $this->assertCount(1, $this->callsWithAction('deleteMessage'), 'the streamed message must be deleted, keeping only the file');
    }

    /**
     * Private chat via the draft stream: the draft keeps existing with the
     * trailing notice while generation continues; the file is only sent by the
     * final send() once the completion is done. There is no streamed message
     * to delete — the draft simply evaporates when replaced by the file.
     */
    public function testPrivateChatDraftCarriesNoticeAndFileIsOnlySentAtTheEnd(): void
    {
        ob_start();
        try {
            $actions = async(fn () => $this->runScenario(100300, withDraftCallback: true, behavior: $this->overlongStreamBehavior()))->await();
        } finally {
            ob_end_clean();
        }

        $drafts = $this->callsWithAction('sendRichMessageDraft');
        $this->assertNotEmpty($drafts, 'drafts must keep flowing while generation continues');
        $lastDraftMarkdown = null;
        foreach ($drafts as $draft) {
            $rich = json_decode((string) $draft['form']['rich_message'], true);
            $lastDraftMarkdown = $rich['markdown'] ?? $lastDraftMarkdown;
        }
        $this->assertStringContainsString(
            self::NOTICE,
            $lastDraftMarkdown,
            'the last draft must carry the trailing notice after its content',
        );
        $this->assertMatchesRegularExpression(
            '/' . preg_quote(self::NOTICE, '/') . '<tg-thinking>/',
            $lastDraftMarkdown,
            'the notice must sit at the end of the content, before the thinking block',
        );
        $this->assertLessThanOrEqual(TelegramRichMarkdown::MAX_LENGTH, mb_strlen($lastDraftMarkdown));

        $documents = array_keys($actions, 'sendDocument');
        $this->assertCount(1, $documents, 'the file must be sent exactly once, after generation completes');
        $lastDraftActionIndex = false;
        foreach ($actions as $index => $action) {
            if ($action === 'sendRichMessageDraft') {
                $lastDraftActionIndex = $index;
            }
        }
        $this->assertNotFalse($lastDraftActionIndex);
        $this->assertGreaterThan($lastDraftActionIndex, $documents[0], 'the file must not be sent while drafts are still flowing');
        $this->assertSame([], $this->callsWithAction('deleteMessage'), 'no streamed message exists to delete in the draft path');
        $this->assertSame([], $this->callsWithAction('sendRichMessage'), 'the final message must be the file, not a rich message');
        $this->assertStringContainsString($this->fullFinalContent(), $this->documentRequestBody());
    }

    private function overlongStreamBehavior(): \Closure
    {
        return function ($streamFunction): string {
            delay(0.2);
            $first = 'Start of a very long response. ';
            $streamFunction($first); // creates the streamed message / first draft

            delay(1.6); // >= edit frequency minimum (1.5s)

            $huge = str_repeat('x', TelegramRichMarkdown::MAX_LENGTH);
            $streamFunction($huge); // crosses the limit: notice edit, further edits stop
            delay(0.3); // let the main process deliver the notice edit

            $extra = ' and a bit more after the limit was hit';

            return $this->fullFinalContent();
        };
    }

    private function fullFinalContent(): string
    {
        return 'Start of a very long response. ' . str_repeat('x', TelegramRichMarkdown::MAX_LENGTH) . ' and a bit more after the limit was hit';
    }

    /**
     * @return list<string>
     */
    private function runScenario(int $chatId, bool $withDraftCallback, \Closure $behavior): array
    {
        [$workerChannel, $mainChannel] = IntegrationTestDsl::createChannelPair();

        $finalMessageTracker = new FinalMessageTracker();
        $chatActionUpdater = new ChatActionUpdater($finalMessageTracker, self::TYPING_INTERVAL, logger: new \Psr\Log\NullLogger());
        $draftUpdater = new DraftUpdater($finalMessageTracker, 999, logger: new \Psr\Log\NullLogger());
        $runningTaskTracker = new RunningTaskTracker($chatActionUpdater, $draftUpdater, $finalMessageTracker, logger: new \Psr\Log\NullLogger());

        $execution = IntegrationTestDsl::makeExecution($mainChannel);
        $mainFuture = async(static fn () => $runningTaskTracker->receive($execution));

        $callback = new EngineProgressUpdateCallback($workerChannel, 1);
        $assistant = new StubStreamingAssistant(
            IntegrationTestDsl::stubPreferenceReader('You are a helpful test assistant.'),
            IntegrationTestDsl::stubPreferenceReader(null),
            IntegrationTestDsl::stubPreferenceReader(null),
            new \Perk11\Viktor89\Test\Support\NullTelegramFileDownloader(),
            new \Perk11\Viktor89\Test\Support\NullAltTextProvider(),
            $behavior,
        );
        $chain = IntegrationTestDsl::buildIncomingMessageChain($chatId);
        if ($withDraftCallback) {
            $assistant->setDraftUpdateCallback(new ChannelDraftUpdateCallback($workerChannel, 1));
        }

        $result = $assistant->processMessageChain($chain, $callback);

        $executor = new ProcessingResultExecutor(
            new \Perk11\Viktor89\Test\Support\NullMessageRepository(),
            true,
            new ChannelBeforeMessageSentNotifier($workerChannel, 1),
            logger: new \Psr\Log\NullLogger(),
        );
        $executor->execute($result);

        delay(0.3);

        $workerChannel->send(new \Perk11\Viktor89\IPC\TaskCompletedMessage(1));
        delay(self::TYPING_INTERVAL * 2);
        $workerChannel->close();
        $mainFuture->await();

        return $this->recordedActions();
    }

    private function lastStreamedEditMarkdown(): ?string
    {
        $markdown = null;
        foreach ($this->recordedCalls() as $call) {
            if ($call['action'] === 'editMessageText' && isset($call['form']['rich_message'])) {
                $rich = json_decode((string) $call['form']['rich_message'], true);
                $markdown = $rich['markdown'] ?? $markdown;
            }
        }

        return $markdown;
    }

    /** @return list<array{action: string, chatId: int, form: array<string, mixed>, text: ?string, draftId: ?int}> */
    private function callsWithAction(string $action): array
    {
        return array_values(array_filter(
            $this->recordedCalls(),
            static fn(array $call): bool => $call['action'] === $action,
        ));
    }

    private function documentRequestBody(): string
    {
        foreach ($this->telegramTransactions as $transaction) {
            if (self::extractActionFromRequest($transaction['request']) === 'sendDocument') {
                return (string) $transaction['request']->getBody();
            }
        }

        return '';
    }
}
