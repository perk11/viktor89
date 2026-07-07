<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\Test\Support\TelegramRecordingTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/IntegrationTestSupport.php';

#[CoversClass(InternalMessage::class)]
class InternalMessageTest extends TestCase
{
    use TelegramRecordingTrait;
    public function testAsResponseToCreatesReplyMessage(): void
    {
        $original = new InternalMessage();
        $original->id = 42;
        $original->chatId = -100123;

        $response = InternalMessage::asResponseTo($original, 'Reply text');

        $this->assertSame(42, $response->replyToMessageId);
        $this->assertSame(-100123, $response->chatId);
        $this->assertSame('Reply text', $response->messageText);
    }

    public function testAsResponseToWithoutTextCreatesMessageWithNullText(): void
    {
        $original = new InternalMessage();
        $original->id = 42;
        $original->chatId = -100123;

        $response = InternalMessage::asResponseTo($original);

        $this->assertSame(42, $response->replyToMessageId);
        $this->assertSame(-100123, $response->chatId);
    }

    public function testIsCommandReturnsTrueForSlashCommands(): void
    {
        $message = new InternalMessage();
        $message->messageText = '/start';
        $this->assertTrue($message->isCommand());

        $message->messageText = '/img A beautiful sunset';
        $this->assertTrue($message->isCommand());
    }

    public function testIsCommandReturnsFalseForNonCommands(): void
    {
        $message = new InternalMessage();
        $message->messageText = 'Hello world';
        $this->assertFalse($message->isCommand());

        $message->messageText = '';
        $this->assertFalse($message->isCommand());

        $message->messageText = 'Not a /command';
        $this->assertFalse($message->isCommand());
    }

    public function testWithReplacedTextReturnsNewMessage(): void
    {
        $original = new InternalMessage();
        $original->id = 42;
        $original->chatId = -100123;
        $original->messageText = 'Original text';

        $new = $original->withReplacedText('New text');

        $this->assertNotSame($original, $new);
        $this->assertSame('New text', $new->messageText);
        $this->assertSame('Original text', $original->messageText);
        // Other properties preserved
        $this->assertSame(42, $new->id);
        $this->assertSame(-100123, $new->chatId);
    }

    public function testFromSqliteAssocPopulatesFields(): void
    {
        $assoc = [
            'id' => 1,
            'chat_id' => -100123,
            'user_id' => 456,
            'date' => 1234567890,
            'type' => 'text',
            'message_thread_id' => 5,
            'reply_to_message' => 99,
            'username' => 'TestUser',
            'message_text' => 'Hello from DB',
            'photo_file_id' => 'AgACAgIAAxkBAA',
            'alt_text' => 'A photo',
            'reasoning' => 'Some reasoning',
        ];

        $message = InternalMessage::fromSqliteAssoc($assoc);

        $this->assertSame(1, $message->id);
        $this->assertSame(-100123, $message->chatId);
        $this->assertSame(456, $message->userId);
        $this->assertSame(1234567890, $message->date);
        $this->assertSame('text', $message->type);
        $this->assertSame(5, $message->messageThreadId);
        $this->assertSame(99, $message->replyToMessageId);
        $this->assertSame('TestUser', $message->userName);
        $this->assertSame('Hello from DB', $message->messageText);
        $this->assertSame('AgACAgIAAxkBAA', $message->photoFileId);
        $this->assertSame('A photo', $message->altText);
        $this->assertSame('Some reasoning', $message->reasoning);
        $this->assertTrue($message->isSaved);
    }

    public function testFromSqliteAssocHandlesNullAltTextAndReasoning(): void
    {
        $assoc = [
            'id' => 2,
            'chat_id' => -100456,
            'user_id' => 789,
            'date' => 1234567890,
            'type' => 'photo',
            'message_thread_id' => null,
            'reply_to_message' => null,
            'username' => 'AnotherUser',
            'message_text' => '',
            'photo_file_id' => null,
            'alt_text' => null,
            'reasoning' => null,
        ];

        $message = InternalMessage::fromSqliteAssoc($assoc);

        $this->assertNull($message->altText);
        $this->assertNull($message->reasoning);
        $this->assertNull($message->messageThreadId);
        $this->assertNull($message->replyToMessageId);
    }

    public function testToolCallsPropertyExistsAndIsArray(): void
    {
        $message = new InternalMessage();
        $this->assertSame([], $message->toolCalls);

        $message->toolCalls = [new \Perk11\Viktor89\Assistant\Tool\ToolCall('c1', 'test', '{}')];
        $this->assertCount(1, $message->toolCalls);
    }

    public function testDefaultValues(): void
    {
        $message = new InternalMessage();

        $this->assertNull($message->id);
        $this->assertNull($message->draftId);
        $this->assertNull($message->messageThreadId);
        $this->assertNull($message->photoFileId);
        $this->assertNull($message->altText);
        $this->assertNull($message->reasoning);
        $this->assertNull($message->reasoningForDisplay);
        $this->assertNull($message->rawMessageText);
        $this->assertFalse($message->isSaved);
        $this->assertFalse($message->removeKeyboard);
        $this->assertFalse($message->forceReply);
        $this->assertSame('Default', $message->parseMode);
    }

    public function testEditRetriesOnRateLimitUntilItSucceeds(): void
    {
        $this->installRecordingTelegramClient();

        $attempt = 0;
        $this->telegramResponseOverride = function (string $action, array $form) use (&$attempt): ?array {
            if ($action !== 'editMessageText') {
                return null;
            }
            $attempt++;
            // Fail the first two attempts with a rate limit, then succeed.
            if ($attempt <= 2) {
                return [
                    'ok' => false,
                    'error_code' => 429,
                    'description' => 'Too Many Requests',
                    'parameters' => ['retry_after' => 1],
                ];
            }

            return null;
        };

        $message = new InternalMessage();
        $message->id = 42;
        $message->chatId = -100;
        $message->parseMode = 'Default';

        ob_start();
        try {
            $response = $message->edit('final content');
        } finally {
            ob_end_clean();
        }

        $this->assertTrue($response->isOk(), 'edit() must keep retrying past repeated 429s until it succeeds');
        $this->assertSame(3, $attempt, 'edit() must retry past repeated 429s (previous behaviour gave up after a single retry)');
        $this->assertSame('final content', $message->messageText);
    }
}
