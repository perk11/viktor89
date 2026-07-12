<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Longman\TelegramBot\Entities\Message;
use Perk11\Viktor89\Engine;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\ProcessingResultExecutor;
use Perk11\Viktor89\Repository\MessageRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Engine::class)]
class EngineTest extends TestCase
{
    private const string BOT_USERNAME = 'TestBot';
    private const int BOT_ID = 123456789;

    protected function setUp(): void
    {
        // InternalMessage::fromTelegramMessage() strips bot mentions using this.
        $_ENV['TELEGRAM_BOT_USERNAME'] = self::BOT_USERNAME;
    }

    private function buildMessage(string $text, string $chatType, int $chatId, int $messageId = 50): Message
    {
        return new Message([
            'message_id' => $messageId,
            'date' => time(),
            'chat' => ['id' => $chatId, 'type' => $chatType],
            'from' => ['id' => 222, 'first_name' => 'Alice', 'is_bot' => false],
            'text' => $text,
        ]);
    }

    private function buildEngine(
        Message $message,
        MessageRepository $messageRepository,
        MessageChainProcessor $fallBackResponder,
    ): Engine {
        $runner = $this->createStub(\Perk11\Viktor89\MessageChainProcessorRunner::class);
        // Nothing in the chain handled the message -> fall through to the fallback.
        $runner->method('run')->willReturn(false);

        $executor = $this->createStub(ProcessingResultExecutor::class);
        $executor->method('execute');

        return new Engine(
            $messageRepository,
            $this->createStub(\Perk11\Viktor89\HistoryReader::class),
            [],
            $runner,
            self::BOT_USERNAME,
            self::BOT_ID,
            $fallBackResponder,
            $this->createStub(ProgressUpdateCallback::class),
            $executor,
        );
    }

    private function noopRepository(): MessageRepository
    {
        $repo = $this->createStub(MessageRepository::class);
        $repo->method('logMessage');

        return $repo;
    }

    public function testPrivateChatNonCommandBuildsContextFromLast100Messages(): void
    {
        $message = $this->buildMessage('hello there', 'private', 11111, messageId: 50);

        // findNPreviousMessagesInChat returns messages in DESC order (newest first).
        $recentMessages = [];
        foreach ([49 => 'msg49', 48 => 'msg48', 47 => 'msg47'] as $id => $text) {
            $m = new InternalMessage();
            $m->id = $id;
            $m->chatId = 11111;
            $m->messageText = $text;
            $recentMessages[] = $m;
        }

        $repository = $this->createMock(MessageRepository::class);
        $repository->method('logMessage');
        $repository
            ->expects($this->once())
            ->method('findNPreviousMessagesInChat')
            ->with(11111, 50, 100, [])
            ->willReturn($recentMessages);

        $capturedChain = null;
        $fallBackResponder = $this->createMock(MessageChainProcessor::class);
        $fallBackResponder
            ->expects($this->once())
            ->method('processMessageChain')
            ->willReturnCallback(function (MessageChain $chain) use (&$capturedChain): ProcessingResult {
                $capturedChain = $chain;

                return new ProcessingResult(null, true);
            });

        $this->buildEngine($message, $repository, $fallBackResponder)->handleMessage($message);

        $this->assertNotNull($capturedChain);
        $messages = $capturedChain->getMessages();
        $this->assertCount(4, $messages, 'Chain = 3 recent messages + the incoming message');
        // Recent history is reversed into chronological order, incoming last.
        $this->assertSame([47, 48, 49, 50], array_map(fn (InternalMessage $m) => $m->id, $messages));
        $this->assertSame('hello there', $capturedChain->last()->messageText);
    }

    public function testPrivateChatCommandIsNotForwardedToAssistant(): void
    {
        $message = $this->buildMessage('/unknowncommand', 'private', 11111, messageId: 50);

        $repository = $this->createMock(MessageRepository::class);
        $repository->method('logMessage');
        $repository->expects($this->never())->method('findNPreviousMessagesInChat');

        $fallBackResponder = $this->createMock(MessageChainProcessor::class);
        $fallBackResponder->expects($this->never())->method('processMessageChain');

        $this->buildEngine($message, $repository, $fallBackResponder)->handleMessage($message);
    }

    public function testGroupChatWithoutMentionOrReplyIsIgnored(): void
    {
        $message = $this->buildMessage('just chatting', 'supergroup', -100200, messageId: 50);

        $fallBackResponder = $this->createMock(MessageChainProcessor::class);
        $fallBackResponder->expects($this->never())->method('processMessageChain');

        $this->buildEngine($message, $this->noopRepository(), $fallBackResponder)->handleMessage($message);
    }

    public function testGroupChatWithMentionReachesAssistant(): void
    {
        $message = $this->buildMessage('hi @' . self::BOT_USERNAME, 'supergroup', -100200, messageId: 50);

        $capturedChain = null;
        $fallBackResponder = $this->createMock(MessageChainProcessor::class);
        $fallBackResponder
            ->expects($this->once())
            ->method('processMessageChain')
            ->willReturnCallback(function (MessageChain $chain) use (&$capturedChain): ProcessingResult {
                $capturedChain = $chain;

                return new ProcessingResult(null, true);
            });

        $this->buildEngine($message, $this->noopRepository(), $fallBackResponder)->handleMessage($message);

        $this->assertNotNull($capturedChain);
        // No history is pulled for group chats; the chain is just the incoming message.
        $this->assertCount(1, $capturedChain->getMessages());
    }
}
