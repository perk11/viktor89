<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\Compaction\CompactionSummary;
use Perk11\Viktor89\Assistant\Compaction\SqliteCompactionSummaryStore;
use Perk11\Viktor89\Database;
use PHPUnit\Framework\TestCase;

class SqliteCompactionSummaryStoreTest extends TestCase
{
    private string $dbName = 'test_compaction_store.db';
    private Database $database;
    private SqliteCompactionSummaryStore $store;

    protected function setUp(): void
    {
        $fullPath = __DIR__ . '/../data/' . $this->dbName;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
        $this->database = new Database(123, $this->dbName);
        $this->store = new SqliteCompactionSummaryStore($this->database);
    }

    protected function tearDown(): void
    {
        $this->database->sqlite3Database->close();
        $fullPath = __DIR__ . '/../data/' . $this->dbName;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
        foreach (['-wal', '-shm'] as $suffix) {
            if (file_exists($fullPath . $suffix)) {
                unlink($fullPath . $suffix);
            }
        }
    }

    public function testStoreAndRetrieve(): void
    {
        $this->assertNull($this->store->findLatestForChain(7, 100));

        $this->store->store(new CompactionSummary(7, 100, 'the summary', 50, 1700000000));

        $retrieved = $this->store->findLatestForChain(7, 100);
        $this->assertNotNull($retrieved);
        $this->assertSame(7, $retrieved->chatId);
        $this->assertSame(100, $retrieved->rootMessageId);
        $this->assertSame('the summary', $retrieved->summary);
        $this->assertSame(50, $retrieved->lastSummarizedMessageId);
        $this->assertSame(1700000000, $retrieved->createdAt);
    }

    public function testStoreReplacesPreviousForSameChain(): void
    {
        $this->store->store(new CompactionSummary(7, 100, 'first', 10, 1700000000));
        $this->store->store(new CompactionSummary(7, 100, 'second', 20, 1700000001));

        $retrieved = $this->store->findLatestForChain(7, 100);
        $this->assertNotNull($retrieved);
        $this->assertSame('second', $retrieved->summary);
        $this->assertSame(20, $retrieved->lastSummarizedMessageId);
    }

    public function testChainsInSameChatAreIndependent(): void
    {
        $this->store->store(new CompactionSummary(7, 100, 'chain100', 10, 1700000000));
        $this->store->store(new CompactionSummary(7, 200, 'chain200', 30, 1700000001));

        $this->assertSame('chain100', $this->store->findLatestForChain(7, 100)->summary);
        $this->assertSame('chain200', $this->store->findLatestForChain(7, 200)->summary);
    }

    public function testChatsAreIndependent(): void
    {
        $this->store->store(new CompactionSummary(7, 100, 'chat7', 10, 1700000000));
        $this->store->store(new CompactionSummary(9, 100, 'chat9', 30, 1700000001));

        $this->assertSame('chat7', $this->store->findLatestForChain(7, 100)->summary);
        $this->assertSame('chat9', $this->store->findLatestForChain(9, 100)->summary);
    }

    public function testClearForChain(): void
    {
        $this->store->store(new CompactionSummary(7, 100, 'chain100', 10, 1700000000));
        $this->assertNotNull($this->store->findLatestForChain(7, 100));

        $this->store->clearForChain(7, 100);
        $this->assertNull($this->store->findLatestForChain(7, 100));
    }
}
