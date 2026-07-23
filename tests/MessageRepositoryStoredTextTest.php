<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Database;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\Repository\MessageRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The "Executing tool" notice lives in the displayed {@see InternalMessage::$messageText}
 * but must never be persisted (and thus replayed to the LLM). When
 * $messageTextForDatabase is set, it — not $messageText — is what is stored.
 */
#[CoversClass(MessageRepository::class)]
class MessageRepositoryStoredTextTest extends TestCase
{
    private Database $database;
    private MessageRepository $repository;
    private const DB_NAME = 'test_stored_text.db';

    protected function setUp(): void
    {
        $this->database = new Database(123, self::DB_NAME);
        $this->repository = new MessageRepository($this->database);
    }

    protected function tearDown(): void
    {
        $this->database->sqlite3Database->close();
        $fullPath = __DIR__ . '/../data/' . self::DB_NAME;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    public function testMessageTextForDatabaseIsPersistedInsteadOfMessageText(): void
    {
        $message = $this->baseMessage(1);
        $message->messageText = "Done.\n>Executing `search` with arguments `{}`\n\n";
        $message->messageTextForDatabase = 'Done.';

        $this->repository->logInternalMessage($message);

        $stored = $this->repository->findMessageByIdInChat(1, -100);
        $this->assertNotNull($stored);
        $this->assertSame('Done.', $stored->messageText);
        $this->assertStringNotContainsString('Executing', $stored->messageText);
    }

    public function testFallsBackToMessageTextWhenNoDatabaseOverride(): void
    {
        $message = $this->baseMessage(2);
        $message->messageText = 'plain stored text';

        $this->repository->logInternalMessage($message);

        $stored = $this->repository->findMessageByIdInChat(2, -100);
        $this->assertNotNull($stored);
        $this->assertSame('plain stored text', $stored->messageText);
    }

    private function baseMessage(int $id): InternalMessage
    {
        $message = new InternalMessage();
        $message->id = $id;
        $message->chatId = -100;
        $message->userId = 999;
        $message->userName = 'Bot';
        $message->type = 'text';
        $message->date = $id;

        return $message;
    }
}
