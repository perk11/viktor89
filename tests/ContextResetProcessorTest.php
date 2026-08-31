<?php

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\Compaction\CompactionSummaryStoreInterface;
use Perk11\Viktor89\ContextResetProcessor;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\ProcessingResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContextResetProcessor::class)]
class ContextResetProcessorTest extends TestCase
{
    private const int PRIVATE_CHAT_ID = 11111;
    private const int GROUP_CHAT_ID = -100200;

    public function testPrivateChatClearsCompaction(): void
    {
        $compactionStore = $this->createMock(CompactionSummaryStoreInterface::class);
        // Private chats keep a single compaction rooted at message id 0; without
        // clearing it, the old conversation summary would survive the reset.
        $compactionStore
            ->expects($this->once())
            ->method('clearForChain')
            ->with(self::PRIVATE_CHAT_ID, 0);
        $processor = new ContextResetProcessor($compactionStore);

        $result = $this->runProcessor($processor, self::PRIVATE_CHAT_ID, 500);

        $this->assertTrue($result->abortProcessing);
        $this->assertNotNull($result->response);
        $this->assertSame(500, $result->response->replyToMessageId);
        $this->assertStringContainsStringIgnoringCase('контекст', $result->response->messageText);
    }

    public function testGroupChatIsRejectedWithoutSideEffects(): void
    {
        $compactionStore = $this->createMock(CompactionSummaryStoreInterface::class);
        $compactionStore->expects($this->never())->method('clearForChain');
        $processor = new ContextResetProcessor($compactionStore);

        $result = $this->runProcessor($processor, self::GROUP_CHAT_ID, 500);

        $this->assertTrue($result->abortProcessing);
        $this->assertNotNull($result->response);
        $this->assertStringContainsStringIgnoringCase('личных', $result->response->messageText);
    }

    #[DataProvider('provideCommandTexts')]
    public function testHistoryAbortMatchesCompleteCommandToken(string $text, bool $matches): void
    {
        $this->assertSame($matches, ContextResetProcessor::isContextResetCommandText($text));
    }

    public static function provideCommandTexts(): array
    {
        return [
            'plain command' => ['/new', true],
            'command directed at bot' => ['/new@Viktor89Bot', true],
            'command with argument' => ['/new let us start over', true],
            'longer command must not match' => ['/newyear', false],
            'regular text' => ['new year', false],
            'command in the middle of text' => ['please /new', false],
        ];
    }

    private function runProcessor(ContextResetProcessor $processor, int $chatId, int $messageId): ProcessingResult
    {
        $message = new InternalMessage();
        $message->id = $messageId;
        $message->chatId = $chatId;
        $message->userId = 222;
        $message->userName = 'Alice';
        $message->messageText = '/new';
        $message->type = 'command';

        $chain = new MessageChain([$message]);

        return $processor->processMessageChain($chain, $this->createStub(ProgressUpdateCallback::class));
    }
}
