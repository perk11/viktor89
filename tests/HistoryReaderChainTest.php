<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Longman\TelegramBot\Entities\Message;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\HistoryReader;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\Repository\MessageRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HistoryReader::class)]
class HistoryReaderChainTest extends TestCase
{
    private const DB_NAME = 'test_history_reader_chain.db';
    private const int BOT_ID = 999;
    private const int CHAT_ID = -100200;

    private Database $database;
    private HistoryReader $historyReader;

    protected function setUp(): void
    {
        $this->database = new Database(self::BOT_ID, self::DB_NAME);
        $repository = new MessageRepository($this->database);
        $this->historyReader = new HistoryReader($repository);
    }

    protected function tearDown(): void
    {
        $this->database->sqlite3Database->close();
        $fullPath = __DIR__ . '/../data/' . self::DB_NAME;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    /**
     * Reproduces the image-generation scenario: an assistant turn produces a
     * photo (M2) and a text reply (M3), both replying to the user's trigger
     * (M1). When the user replies to the text reply, the photo must still be
     * part of the chain so the model can reference it on the next turn.
     */
    public function testIncludesSiblingBotPhotoWhenReplyingToTextReply(): void
    {
        $this->logMessage(1, self::CHAT_ID, 222, 'Alice', 'draw a cat');
        // Generated photo, sent as a separate bot message replying to M1.
        $this->logMessage(2, self::CHAT_ID, self::BOT_ID, 'Viktor89', '', replyToMessageId: 1, photoFileId: 'photo-file-id');
        // Assistant text reply, also replying to M1.
        $this->logMessage(3, self::CHAT_ID, self::BOT_ID, 'Viktor89', "Here's a cute cat", replyToMessageId: 1);

        // User replies to the text reply (M3), not to the photo.
        $currentMessage = $this->buildReplyMessage(4, 'make it more serious', repliesTo: 3);

        $chain = $this->historyReader->getPreviousMessages($currentMessage, 999, 999, 0, self::BOT_ID);

        $ids = array_map(fn (InternalMessage $m) => $m->id, $chain);
        $this->assertSame([1, 2, 3], $ids, 'photo (M2) must appear between the trigger and the text reply');

        $photoMessage = $chain[1];
        $this->assertSame(2, $photoMessage->id);
        $this->assertSame('photo-file-id', $photoMessage->photoFileId);
    }

    /**
     * Replying to the photo (M2) instead must still surface the text reply (M3)
     * so the assistant's tool-call turn is not lost.
     */
    public function testIncludesSiblingBotReplyWhenReplyingToPhoto(): void
    {
        $this->logMessage(1, self::CHAT_ID, 222, 'Alice', 'draw a cat');
        $this->logMessage(2, self::CHAT_ID, self::BOT_ID, 'Viktor89', '', replyToMessageId: 1, photoFileId: 'photo-file-id');
        $this->logMessage(3, self::CHAT_ID, self::BOT_ID, 'Viktor89', "Here's a cute cat", replyToMessageId: 1);

        $currentMessage = $this->buildReplyMessage(4, 'make it more serious', repliesTo: 2);

        $chain = $this->historyReader->getPreviousMessages($currentMessage, 999, 999, 0, self::BOT_ID);

        $ids = array_map(fn (InternalMessage $m) => $m->id, $chain);
        $this->assertSame([1, 2, 3], $ids);
    }

    /**
     * Sibling inclusion is scoped to bot messages: unrelated replies from other
     * users to the same trigger must not leak into the chain.
     */
    public function testDoesNotIncludeNonBotSiblings(): void
    {
        $this->logMessage(1, self::CHAT_ID, 222, 'Alice', 'draw a cat');
        $this->logMessage(2, self::CHAT_ID, self::BOT_ID, 'Viktor89', '', replyToMessageId: 1, photoFileId: 'photo-file-id');
        $this->logMessage(3, self::CHAT_ID, self::BOT_ID, 'Viktor89', "Here's a cute cat", replyToMessageId: 1);
        // Another user replying to the same trigger — must be excluded.
        $this->logMessage(5, self::CHAT_ID, 333, 'Bob', 'lol nice', replyToMessageId: 1);

        $currentMessage = $this->buildReplyMessage(6, 'make it more serious', repliesTo: 3);

        $chain = $this->historyReader->getPreviousMessages($currentMessage, 999, 999, 0, self::BOT_ID);

        $ids = array_map(fn (InternalMessage $m) => $m->id, $chain);
        $this->assertSame([1, 2, 3], $ids);
    }

    private function logMessage(
        int $id,
        int $chatId,
        int $userId,
        string $userName,
        string $text,
        ?int $replyToMessageId = null,
        ?string $photoFileId = null,
    ): void {
        $message = new InternalMessage();
        $message->id = $id;
        $message->chatId = $chatId;
        $message->userId = $userId;
        $message->userName = $userName;
        $message->messageText = $text;
        $message->type = $photoFileId !== null ? 'photo' : 'text';
        $message->date = $id;
        $message->replyToMessageId = $replyToMessageId;
        $message->photoFileId = $photoFileId;

        (new MessageRepository($this->database))->logInternalMessage($message);
    }

    private function buildReplyMessage(int $messageId, string $text, int $repliesTo): Message
    {
        return new Message(
            [
                'message_id' => $messageId,
                'date' => time(),
                'chat' => ['id' => self::CHAT_ID, 'type' => 'supergroup'],
                'from' => ['id' => 222, 'first_name' => 'Alice', 'is_bot' => false],
                'text' => $text,
                'reply_to_message' => [
                    'message_id' => $repliesTo,
                    'date' => time(),
                    'chat' => ['id' => self::CHAT_ID, 'type' => 'supergroup'],
                    'from' => ['id' => self::BOT_ID, 'first_name' => 'Viktor89', 'is_bot' => true],
                    'text' => 'previous bot message',
                ],
            ],
            'Viktor89',
        );
    }
}
