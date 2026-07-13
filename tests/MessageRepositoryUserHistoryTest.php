<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Database;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\Repository\MessageRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageRepository::class)]
class MessageRepositoryUserHistoryTest extends TestCase
{
    private Database $database;
    private MessageRepository $repository;
    private const DB_NAME = 'test_user_history.db';

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

    public function testReturnsOnlyMessagesByUserInChatOrderedNewestFirst(): void
    {
        $this->logMessage(1, -100, 500, 'alice-old', 'first from alice');
        $this->logMessage(2, -100, 600, 'bob', 'only bob');
        $this->logMessage(3, -100, 500, 'alice-new', 'second from alice');
        // Different chat — must be excluded.
        $this->logMessage(4, -999, 500, 'alice-other-chat', 'elsewhere');

        $messages = $this->repository->findLastMessagesByUserInChat(-100, 500, 100);

        $this->assertCount(2, $messages);
        $this->assertSame(3, $messages[0]->id, 'newest first');
        $this->assertSame('second from alice', $messages[0]->messageText);
        $this->assertSame(1, $messages[1]->id);
        $this->assertSame('first from alice', $messages[1]->messageText);
    }

    public function testRespectsLimitKeepingMostRecent(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->logMessage($i, -100, 700, 'u', "msg $i");
        }

        $messages = $this->repository->findLastMessagesByUserInChat(-100, 700, 3);

        $this->assertCount(3, $messages);
        $this->assertSame([5, 4, 3], array_map(fn (InternalMessage $m) => $m->id, $messages));
    }

    public function testReturnsEmptyWhenNoMessages(): void
    {
        $this->assertSame([], $this->repository->findLastMessagesByUserInChat(-100, 404, 100));
    }

    private function logMessage(int $id, int $chatId, int $userId, string $userName, string $text): void
    {
        $message = new InternalMessage();
        $message->id = $id;
        $message->chatId = $chatId;
        $message->userId = $userId;
        $message->userName = $userName;
        $message->messageText = $text;
        $message->type = 'text';
        $message->date = $id;

        $this->repository->logInternalMessage($message);
    }
}
