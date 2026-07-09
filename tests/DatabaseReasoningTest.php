<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Database;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\Repository\MessageRepository;
use PHPUnit\Framework\TestCase;

class DatabaseReasoningTest extends TestCase
{
    private string $dbPath;
    private Database $database;
    private MessageRepository $messageRepository;

    protected function setUp(): void
    {
        $this->dbPath = 'test_reasoning.db';
        $fullPath = __DIR__ . '/../data/' . $this->dbPath;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
        $this->database = new Database(123, $this->dbPath);
        $this->messageRepository = new MessageRepository($this->database);
    }

    protected function tearDown(): void
    {
        $fullPath = __DIR__ . '/../data/' . $this->dbPath;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    public function testAssistantContextToOpenAiMessagesArrayWithReasoning(): void
    {
        $context = new \Perk11\Viktor89\Assistant\AssistantContext();
        $message = new \Perk11\Viktor89\Assistant\AssistantContextMessage();
        $message->isUser = false;
        $message->text = 'Hello';
        $message->reasoning = 'Thinking...';
        $context->messages[] = $message;

        $array = $context->toOpenAiMessagesArray();

        $this->assertCount(1, $array);
        $this->assertSame('assistant', $array[0]['role']);
        $this->assertSame('Hello', $array[0]['content']);
        $this->assertSame('Thinking...', $array[0]['reasoning_content']);
    }

    public function testAssistantContextToOpenAiMessagesArrayMultipart(): void
    {
        $context = new \Perk11\Viktor89\Assistant\AssistantContext();
        $message = new \Perk11\Viktor89\Assistant\AssistantContextMessage();
        $message->isUser = true;
        $message->text = 'Check this';
        // Use a real PNG header so guessFileExtension works
        $message->photo = "\x89PNG\r\n\x1a\n" . str_repeat('A', 10);
        $context->messages[] = $message;

        $array = $context->toOpenAiMessagesArray();

        $this->assertCount(1, $array);
        $this->assertIsArray($array[0]['content']);
        $this->assertCount(2, $array[0]['content']);
        $this->assertSame('text', $array[0]['content'][0]['type']);
        $this->assertSame('image_url', $array[0]['content'][1]['type']);
    }

    public function testReasoningIsSavedAndLoaded(): void
    {
        $message = new InternalMessage();
        $message->id = 1;
        $message->chatId = 100;
        $message->userId = 200;
        $message->userName = 'TestUser';
        $message->messageText = 'Hello';
        $message->type = 'text';
        $message->date = time();
        $message->reasoning = 'This is the reasoning context.';

        $this->messageRepository->logInternalMessage($message);

        $loadedMessage = $this->messageRepository->findMessageByIdInChat(1, 100);

        $this->assertNotNull($loadedMessage);
        $this->assertSame('This is the reasoning context.', $loadedMessage->reasoning);
        $this->assertSame('Hello', $loadedMessage->messageText);
    }

    public function testReasoningForDisplayIsNotSavedToMessageText(): void
    {
        $message = new InternalMessage();
        $message->id = 3;
        $message->chatId = 100;
        $message->userId = 200;
        $message->userName = 'TestUser';
        $message->messageText = 'Hello world';
        $message->type = 'text';
        $message->date = time();
        $message->reasoning = 'Thinking about life';
        $message->reasoningForDisplay = "<details>\n<summary>Thinking</summary>\nThinking about life</details>\n";

        $this->messageRepository->logInternalMessage($message);

        $loadedMessage = $this->messageRepository->findMessageByIdInChat(3, 100);

        $this->assertNotNull($loadedMessage);
        $this->assertSame('Thinking about life', $loadedMessage->reasoning);
        $this->assertSame('Hello world', $loadedMessage->messageText);
    }

    public function testReasoningIsNullByDefault(): void
    {
        $message = new InternalMessage();
        $message->id = 2;
        $message->chatId = 100;
        $message->userId = 200;
        $message->userName = 'TestUser';
        $message->messageText = 'Hello without reasoning';
        $message->type = 'text';
        $message->date = time();
        $message->reasoning = null;

        $this->messageRepository->logInternalMessage($message);

        $loadedMessage = $this->messageRepository->findMessageByIdInChat(2, 100);

        $this->assertNotNull($loadedMessage);
        $this->assertNull($loadedMessage->reasoning);
        $this->assertSame('Hello without reasoning', $loadedMessage->messageText);
    }

    public function testInternalMessageFromSqliteAssoc(): void
    {
        $result = [
            'id' => 1,
            'chat_id' => 100,
            'user_id' => 200,
            'date' => time(),
            'type' => 'text',
            'message_thread_id' => null,
            'reply_to_message' => null,
            'username' => 'dbuser',
            'message_text' => 'dbtext',
            'photo_file_id' => 'dbphoto',
            'alt_text' => 'dbalt',
            'reasoning' => 'dbreasoning',
        ];

        $message = InternalMessage::fromSqliteAssoc($result);

        $this->assertSame('dbuser', $message->userName);
        $this->assertSame('dbtext', $message->messageText);
        $this->assertSame('dbphoto', $message->photoFileId);
        $this->assertSame('dbalt', $message->altText);
        $this->assertSame('dbreasoning', $message->reasoning);
    }
}
