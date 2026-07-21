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
        return new Message(
            [
                'message_id' => $messageId,
                'date' => time(),
                'chat' => ['id' => $chatId, 'type' => $chatType],
                'from' => ['id' => 222, 'first_name' => 'Alice', 'is_bot' => false],
                'text' => $text,
            ],
            self::BOT_USERNAME,
        );
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

    public function testGroupChatUnrecognisedCommandIsNotForwardedToAssistant(): void
    {
        // Regression: an unrecognised command in a group chat used to fall
        // through to the fallback assistant. It must now be ignored, just like
        // a private-chat command.
        $message = $this->buildMessage('/totallyunknowncommand', 'supergroup', -100200, messageId: 50);

        $fallBackResponder = $this->createMock(MessageChainProcessor::class);
        $fallBackResponder->expects($this->never())->method('processMessageChain');

        $this->buildEngine($message, $this->noopRepository(), $fallBackResponder)->handleMessage($message);
    }

    public function testGroupChatCommandDirectedAtBotIsNotForwardedToAssistant(): void
    {
        // A command suffixed with @bot still contains a mention substring, but
        // it is an unrecognised command and must not trigger the assistant.
        $message = $this->buildMessage(
            '/totallyunknowncommand@' . self::BOT_USERNAME,
            'supergroup',
            -100200,
            messageId: 50,
        );

        $fallBackResponder = $this->createMock(MessageChainProcessor::class);
        $fallBackResponder->expects($this->never())->method('processMessageChain');

        $this->buildEngine($message, $this->noopRepository(), $fallBackResponder)->handleMessage($message);
    }

    public function testGroupChatReplyToBotReachesAssistant(): void
    {
        // A non-command reply to one of the bot's messages keeps the
        // conversation going (this also covers /assistant reply chains that
        // were not handled by the dedicated processor).
        $message = $this->buildMessageReplyingToBot('why though', 'supergroup', -100200, messageId: 50);

        $fallBackResponder = $this->createMock(MessageChainProcessor::class);
        $fallBackResponder->expects($this->once())->method('processMessageChain')
            ->willReturn(new ProcessingResult(null, true));

        $this->buildEngine($message, $this->noopRepository(), $fallBackResponder)->handleMessage($message);
    }

    public function testReplyEnrichmentTargetsRepliedMessageWhenSiblingIsPresent(): void
    {
        // User replies to the bot's generated photo (id 48). HistoryReader
        // returns a chain whose last element is a sibling bot message (id 49,
        // the text reply) that comes AFTER the replied-to photo. The fresh
        // Telegram data must enrich the replied-to photo (id 48), not the last
        // element, or the text reply is corrupted with the photo's data.
        $message = new Message(
            [
                'message_id' => 50,
                'date' => time(),
                'chat' => ['id' => -100200, 'type' => 'supergroup'],
                'from' => ['id' => 222, 'first_name' => 'Alice', 'is_bot' => false],
                'text' => 'make it more serious',
                'reply_to_message' => [
                    'message_id' => 48,
                    'date' => time(),
                    'chat' => ['id' => -100200, 'type' => 'supergroup'],
                    'from' => ['id' => self::BOT_ID, 'first_name' => 'Viktor89', 'is_bot' => true],
                    'photo' => [
                        ['file_id' => 'small', 'file_unique_id' => 'u1', 'width' => 10, 'height' => 10, 'file_size' => 100],
                        ['file_id' => 'large', 'file_unique_id' => 'u2', 'width' => 100, 'height' => 100, 'file_size' => 1000],
                    ],
                ],
            ],
            self::BOT_USERNAME,
        );

        $trigger = new InternalMessage();
        $trigger->id = 47;
        $trigger->chatId = -100200;
        $trigger->userId = 222;
        $trigger->userName = 'Alice';
        $trigger->messageText = 'draw a cat';
        $trigger->type = 'text';

        $photo = new InternalMessage();
        $photo->id = 48;
        $photo->chatId = -100200;
        $photo->userId = self::BOT_ID;
        $photo->userName = 'Viktor89';
        $photo->messageText = '';
        $photo->photoFileId = null;
        $photo->type = 'photo';

        $textReply = new InternalMessage();
        $textReply->id = 49;
        $textReply->chatId = -100200;
        $textReply->userId = self::BOT_ID;
        $textReply->userName = 'Viktor89';
        $textReply->messageText = "Here's a cute cat";
        $textReply->photoFileId = null;
        $textReply->type = 'text';

        $historyReader = $this->createStub(\Perk11\Viktor89\HistoryReader::class);
        $historyReader->method('getPreviousMessages')->willReturn([$trigger, $photo, $textReply]);

        $runner = $this->createStub(\Perk11\Viktor89\MessageChainProcessorRunner::class);
        $runner->method('run')->willReturn(false);
        $executor = $this->createStub(ProcessingResultExecutor::class);

        $capturedChain = null;
        $fallBackResponder = $this->createMock(MessageChainProcessor::class);
        $fallBackResponder->expects($this->once())->method('processMessageChain')
            ->willReturnCallback(function (MessageChain $chain) use (&$capturedChain): ProcessingResult {
                $capturedChain = $chain;

                return new ProcessingResult(null, true);
            });

        $engine = new Engine(
            $this->noopRepository(),
            $historyReader,
            [],
            $runner,
            self::BOT_USERNAME,
            self::BOT_ID,
            $fallBackResponder,
            $this->createStub(ProgressUpdateCallback::class),
            $executor,
        );
        $engine->handleMessage($message);

        $byId = [];
        foreach ($capturedChain->getMessages() as $m) {
            $byId[$m->id] = $m;
        }
        $this->assertSame('large', $byId[48]->photoFileId, 'replied-to photo is enriched with the largest file id');
        $this->assertNull($byId[49]->photoFileId, 'sibling text reply must not be corrupted with the photo');
        $this->assertSame("Here's a cute cat", $byId[49]->messageText);
    }

    public function testGroupChatReplyToOtherUserIsIgnored(): void
    {
        // Replying to a different user (not the bot) without a mention is ignored.
        $message = new Message(
            [
                'message_id' => 50,
                'date' => time(),
                'chat' => ['id' => -100200, 'type' => 'supergroup'],
                'from' => ['id' => 222, 'first_name' => 'Alice', 'is_bot' => false],
                'text' => 'what did you mean',
                'reply_to_message' => [
                    'message_id' => 49,
                    'date' => time(),
                    'chat' => ['id' => -100200, 'type' => 'supergroup'],
                    'from' => ['id' => 333, 'first_name' => 'Bob', 'is_bot' => false],
                    'text' => 'something',
                ],
            ],
            self::BOT_USERNAME,
        );

        $fallBackResponder = $this->createMock(MessageChainProcessor::class);
        $fallBackResponder->expects($this->never())->method('processMessageChain');

        $this->buildEngine($message, $this->noopRepository(), $fallBackResponder)->handleMessage($message);
    }

    private function buildMessageReplyingToBot(string $text, string $chatType, int $chatId, int $messageId = 50): Message
    {
        return new Message(
            [
                'message_id' => $messageId,
                'date' => time(),
                'chat' => ['id' => $chatId, 'type' => $chatType],
                'from' => ['id' => 222, 'first_name' => 'Alice', 'is_bot' => false],
                'text' => $text,
                'reply_to_message' => [
                    'message_id' => $messageId - 1,
                    'date' => time(),
                    'chat' => ['id' => $chatId, 'type' => $chatType],
                    'from' => ['id' => self::BOT_ID, 'first_name' => 'Viktor89', 'is_bot' => true],
                    'text' => 'previous bot message',
                ],
            ],
            self::BOT_USERNAME,
        );
    }
}
