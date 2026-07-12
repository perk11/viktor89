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

    public function testUpsertAndFind(): void
    {
        $metadata = new MessageMetadata(100, 1, 'gpt-4o', 'Be helpful', 7, 'A cute cat');
        $this->repository->upsert($metadata);

        $loaded = $this->repository->findByMessageIdInChat(1, 100);
        $this->assertNotNull($loaded);
        $this->assertSame(100, $loaded->chatId);
        $this->assertSame(1, $loaded->messageId);
        $this->assertSame('gpt-4o', $loaded->model);
        $this->assertSame('Be helpful', $loaded->systemPrompt);
        $this->assertSame(7, $loaded->personaId);
        $this->assertSame('A cute cat', $loaded->caption);
        $this->assertTrue($loaded->hasAny());
    }

    public function testUpsertOverwritesExisting(): void
    {
        $this->repository->upsert(new MessageMetadata(100, 1, 'old-model'));
        $this->repository->upsert(new MessageMetadata(100, 1, 'new-model'));

        $loaded = $this->repository->findByMessageIdInChat(1, 100);
        $this->assertNotNull($loaded);
        $this->assertSame('new-model', $loaded->model);
    }

    public function testHasAnyIsFalseWhenAllNull(): void
    {
        $metadata = new MessageMetadata(100, 1);
        $this->assertFalse($metadata->hasAny());
    }

    public function testMetadataIsScopedToChat(): void
    {
        $this->repository->upsert(new MessageMetadata(100, 1, 'model-a'));
        $this->repository->upsert(new MessageMetadata(200, 1, 'model-b'));

        $this->assertSame('model-a', $this->repository->findByMessageIdInChat(1, 100)->model);
        $this->assertSame('model-b', $this->repository->findByMessageIdInChat(1, 200)->model);
    }
}
