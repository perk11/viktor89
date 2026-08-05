<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Database;
use Perk11\Viktor89\MessageMetadata;
use Perk11\Viktor89\Repository\MessageMetadataRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageMetadataRepository::class)]
#[CoversClass(MessageMetadata::class)]
class MessageMetadataRepositoryTest extends TestCase
{
    private Database $database;
    private MessageMetadataRepository $repository;

    protected function setUp(): void
    {
        $this->database = new Database(123, 'test_metadata.db');
        $this->repository = new MessageMetadataRepository($this->database);
    }

    protected function tearDown(): void
    {
        $fullPath = __DIR__ . '/../data/test_metadata.db';
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    public function testReturnsNullWhenNoMetadata(): void
    {
        $this->assertNull($this->repository->findByMessageIdInChat(1, 100));
    }

    public function testInsertAndFind(): void
    {
        $metadata = new MessageMetadata(100, 1, 'gpt-4o', 'Be helpful', 7, 'A cute cat');
        $this->repository->insert($metadata);

        $loaded = $this->repository->findByMessageIdInChat(1, 100);
        $this->assertNotNull($loaded);
        $this->assertSame(100, $loaded->chatId);
        $this->assertSame(1, $loaded->messageId);
        $this->assertSame('gpt-4o', $loaded->model);
        $this->assertSame('Be helpful', $loaded->systemPrompt);
        $this->assertSame(7, $loaded->personaId);
        $this->assertSame('A cute cat', $loaded->caption);
        $this->assertNull($loaded->processedPrompt);
        $this->assertTrue($loaded->hasAny());
    }

    public function testInsertAndFindProcessedPrompt(): void
    {
        $metadata = new MessageMetadata(
            100,
            1,
            caption: 'a dog on a beach',
            processedPrompt: 'integrated_multimodal_description: [Shot 1] ...',
        );
        $this->repository->insert($metadata);

        $loaded = $this->repository->findByMessageIdInChat(1, 100);
        $this->assertNotNull($loaded);
        $this->assertSame('a dog on a beach', $loaded->caption);
        $this->assertSame('integrated_multimodal_description: [Shot 1] ...', $loaded->processedPrompt);
    }

    public function testProcessedPromptColumnIsMigratedOnLegacyDatabase(): void
    {
        // Simulate a database created before the column existed: drop it, then
        // rebuild the table without it and let the repository migrate it.
        $this->database->sqlite3Database->exec('DROP TABLE message_metadata');
        $this->database->sqlite3Database->exec(
            'CREATE TABLE message_metadata (
                chat_id bigint NOT NULL,
                message_id bigint NOT NULL,
                model text DEFAULT NULL,
                system_prompt text DEFAULT NULL,
                persona_id integer DEFAULT NULL,
                caption text DEFAULT NULL,
                PRIMARY KEY (chat_id, message_id)
            )'
        );

        // Constructing the repository must add the missing column idempotently.
        $repository = new MessageMetadataRepository($this->database);
        $this->assertTrue($repository->insert(new MessageMetadata(100, 1, caption: 'orig', processedPrompt: 'rewritten')));

        $loaded = $repository->findByMessageIdInChat(1, 100);
        $this->assertNotNull($loaded);
        $this->assertSame('rewritten', $loaded->processedPrompt);

        // A second construction is a no-op (the column already exists).
        new MessageMetadataRepository($this->database);
    }

    public function testInsertingSameMessageTwiceFails(): void
    {
        $this->assertTrue($this->repository->insert(new MessageMetadata(100, 1, 'old-model')));

        // Second insert on the same (chat_id, message_id) primary key fails.
        @$this->assertFalse($this->repository->insert(new MessageMetadata(100, 1, 'new-model')));

        // The original value is preserved.
        $loaded = $this->repository->findByMessageIdInChat(1, 100);
        $this->assertNotNull($loaded);
        $this->assertSame('old-model', $loaded->model);
    }

    public function testHasAnyIsFalseWhenAllNull(): void
    {
        $metadata = new MessageMetadata(100, 1);
        $this->assertFalse($metadata->hasAny());
    }

    public function testMetadataIsScopedToChat(): void
    {
        $this->repository->insert(new MessageMetadata(100, 1, 'model-a'));
        $this->repository->insert(new MessageMetadata(200, 1, 'model-b'));

        $this->assertSame('model-a', $this->repository->findByMessageIdInChat(1, 100)->model);
        $this->assertSame('model-b', $this->repository->findByMessageIdInChat(1, 200)->model);
    }
}
