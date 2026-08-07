<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Database;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\Repository\MessageRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageRepository::class)]
class MessageRepositoryVideoFileIdTest extends TestCase
{
    private Database $database;
    private const DB_NAME = 'test_video_file_id.db';

    protected function setUp(): void
    {
        $this->database = new Database(123, self::DB_NAME);
    }

    protected function tearDown(): void
    {
        $this->database->sqlite3Database->close();
        $fullPath = __DIR__ . '/../data/' . self::DB_NAME;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    public function testVideoFileIdIsPersistedAndRestored(): void
    {
        $repository = new MessageRepository($this->database);

        $message = new InternalMessage();
        $message->id = 1;
        $message->chatId = -100;
        $message->userId = 999;
        $message->userName = 'User';
        $message->type = 'video';
        $message->date = 1;
        $message->messageText = '';
        $message->photoFileId = 'photo-1';
        $message->videoFileId = 'video-file-id-1';

        $repository->logInternalMessage($message);

        $stored = $repository->findMessageByIdInChat(1, -100);
        $this->assertNotNull($stored);
        $this->assertSame('video-file-id-1', $stored->videoFileId);
        $this->assertSame('photo-1', $stored->photoFileId);
    }

    public function testVideoFileIdDefaultsToNullWhenAbsent(): void
    {
        $repository = new MessageRepository($this->database);

        $message = new InternalMessage();
        $message->id = 2;
        $message->chatId = -100;
        $message->userId = 999;
        $message->userName = 'User';
        $message->type = 'text';
        $message->date = 2;
        $message->messageText = 'hello';

        $repository->logInternalMessage($message);

        $stored = $repository->findMessageByIdInChat(2, -100);
        $this->assertNotNull($stored);
        $this->assertNull($stored->videoFileId);
    }

    public function testVideoFileIdColumnIsMigratedOnLegacyDatabase(): void
    {
        // Simulate a database created before the column existed.
        $this->database->sqlite3Database->exec('DROP TABLE message');
        $this->database->sqlite3Database->exec(
            'CREATE TABLE message (
                chat_id bigint,
                id bigint UNSIGNED,
                type varchar,
                message_thread_id bigint DEFAULT NULL,
                user_id bigint,
                `date` timestamp,
                reply_to_message bigint UNSIGNED DEFAULT NULL,
                username varchar,
                message_text varchar,
                photo_file_id varchar DEFAULT NULL,
                alt_text varchar DEFAULT NULL,
                reasoning varchar DEFAULT NULL,
                receiver_user_id bigint DEFAULT NULL
            )'
        );

        // Constructing the repository must add the missing column idempotently.
        $repository = new MessageRepository($this->database);

        $message = new InternalMessage();
        $message->id = 3;
        $message->chatId = -100;
        $message->userId = 999;
        $message->userName = 'User';
        $message->type = 'video';
        $message->date = 3;
        $message->messageText = '';
        $message->videoFileId = 'legacy-video-id';
        $repository->logInternalMessage($message);

        $stored = $repository->findMessageByIdInChat(3, -100);
        $this->assertNotNull($stored);
        $this->assertSame('legacy-video-id', $stored->videoFileId);

        // A second construction is a no-op (the column already exists).
        new MessageRepository($this->database);
    }
}
