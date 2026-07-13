<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\AssistantContextMessage;
use Perk11\Viktor89\Assistant\Compaction\CompactionKey;
use Perk11\Viktor89\Assistant\Compaction\CompactionSummary;
use Perk11\Viktor89\Assistant\Compaction\CompactionSummaryStoreInterface;
use Perk11\Viktor89\Assistant\ContextCompactor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Verifies that a compaction produced by ContextCompactor is recorded in a
 * store and that applyStoredCompaction() reuses it on the next request instead
 * of re-summarizing the same messages.
 */
class ContextCompactorPersistenceTest extends TestCase
{
    private const int CHAT_ID = 42;
    private const int ROOT_ID = 10;

    public function testCompactWithKeyPersistsSummary(): void
    {
        $store = new InMemoryCompactionStore();
        $compactor = new ContextCompactor(
            fn(string $p): string => 'persisted summary',
            new NullLogger(),
            $store,
            maxRecentCharacters: 5, // forces compaction of old messages
        );

        $ctx = $this->makeContextWithIds([
            ['isUser' => true, 'text' => 'old1', 'id' => 1],
            ['isUser' => true, 'text' => 'old2', 'id' => 2],
            ['isUser' => true, 'text' => 'recent', 'id' => 3],
        ]);

        $key = new CompactionKey(self::CHAT_ID, self::ROOT_ID);
        $compactor->compact($ctx, $key);

        $stored = $store->findLatestForChain(self::CHAT_ID, self::ROOT_ID);
        $this->assertNotNull($stored);
        $this->assertSame('persisted summary', $stored->summary);
        $this->assertSame(2, $stored->lastSummarizedMessageId);
        $this->assertSame(self::ROOT_ID, $stored->rootMessageId);
    }

    public function testCompactWithoutKeyDoesNotPersist(): void
    {
        $store = new InMemoryCompactionStore();
        $compactor = new ContextCompactor(
            fn(string $p): string => 'summary',
            new NullLogger(),
            $store,
            maxRecentCharacters: 5,
        );

        $ctx = $this->makeContextWithIds([
            ['isUser' => true, 'text' => 'old1', 'id' => 1],
            ['isUser' => true, 'text' => 'recent', 'id' => 2],
        ]);

        $compactor->compact($ctx);
        $this->assertNull($store->findLatestForChain(self::CHAT_ID, self::ROOT_ID));
    }

    public function testApplyStoredCompactionDropsCoveredMessages(): void
    {
        $store = new InMemoryCompactionStore();
        $store->store(new CompactionSummary(self::CHAT_ID, self::ROOT_ID, 'earlier summary', 3, time()));

        $compactor = new ContextCompactor(
            fn(string $p): string => 'should not be called',
            new NullLogger(),
            $store,
            maxRecentCharacters: 5,
        );

        // Simulates a subsequent request: messages 1-3 are already summarized,
        // only 4 and 5 are new.
        $ctx = $this->makeContextWithIds([
            ['isUser' => true, 'text' => 'old1', 'id' => 1],
            ['isUser' => true, 'text' => 'old2', 'id' => 2],
            ['isUser' => true, 'text' => 'old3', 'id' => 3],
            ['isUser' => true, 'text' => 'new4', 'id' => 4],
            ['isUser' => true, 'text' => 'new5', 'id' => 5],
        ]);

        $result = $compactor->applyStoredCompaction(new CompactionKey(self::CHAT_ID, self::ROOT_ID), $ctx);

        // 1 summary message + 2 recent messages
        $this->assertCount(3, $result->messages);
        $this->assertStringContainsString('earlier summary', $result->messages[0]->text);
        $this->assertSame('new4', $result->messages[1]->text);
        $this->assertSame('new5', $result->messages[2]->text);
    }

    public function testIndependentChainsDoNotShareCompaction(): void
    {
        $store = new InMemoryCompactionStore();
        $compactor = new ContextCompactor(
            function (string $p): string {
                return str_contains($p, 'a1') ? 'chain A summary' : 'chain B summary';
            },
            new NullLogger(),
            $store,
            maxRecentCharacters: 5,
        );

        // Two independent reply chains in the same chat with different roots.
        $compactor->compact($this->makeContextWithIds([
            ['isUser' => true, 'text' => 'a1', 'id' => 1],
            ['isUser' => true, 'text' => 'a2', 'id' => 2],
        ]), new CompactionKey(self::CHAT_ID, 100));

        $compactor->compact($this->makeContextWithIds([
            ['isUser' => true, 'text' => 'b1', 'id' => 5],
            ['isUser' => true, 'text' => 'b2', 'id' => 6],
        ]), new CompactionKey(self::CHAT_ID, 200));

        // Each chain has its own summary.
        $summaryA = $store->findLatestForChain(self::CHAT_ID, 100);
        $summaryB = $store->findLatestForChain(self::CHAT_ID, 200);
        $this->assertNotNull($summaryA);
        $this->assertNotNull($summaryB);
        $this->assertSame('chain A summary', $summaryA->summary);
        $this->assertSame('chain B summary', $summaryB->summary);
        $this->assertSame(1, $summaryA->lastSummarizedMessageId);
        $this->assertSame(5, $summaryB->lastSummarizedMessageId);
    }

    public function testApplyStoredCompactionReturnsUnchangedWhenNothingToDrop(): void
    {
        $store = new InMemoryCompactionStore();
        // Boundary is below all current message ids, so nothing should be dropped.
        $store->store(new CompactionSummary(self::CHAT_ID, self::ROOT_ID, 'old summary', 0, time()));

        $compactor = new ContextCompactor(
            fn(string $p): string => 'summary',
            new NullLogger(),
            $store,
            maxRecentCharacters: 5,
        );

        $ctx = $this->makeContextWithIds([
            ['isUser' => true, 'text' => 'msg1', 'id' => 1],
            ['isUser' => true, 'text' => 'msg2', 'id' => 2],
        ]);

        $result = $compactor->applyStoredCompaction(new CompactionKey(self::CHAT_ID, self::ROOT_ID), $ctx);
        $this->assertSame($ctx, $result);
    }

    /**
     * @param array<array{isUser: bool, text: string, id: int}> $messages
     */
    private function makeContextWithIds(array $messages): AssistantContext
    {
        $ctx = new AssistantContext();
        foreach ($messages as $m) {
            $msg = new AssistantContextMessage();
            $msg->isUser = $m['isUser'];
            $msg->text = $m['text'];
            $msg->messageId = $m['id'];
            $ctx->messages[] = $msg;
        }
        return $ctx;
    }
}

/**
 * Simple in-memory implementation of the store for unit-testing the compactor
 * without touching SQLite.
 */
final class InMemoryCompactionStore implements CompactionSummaryStoreInterface
{
    /** @var array<string, CompactionSummary> */
    private array $summaries = [];

    public function store(CompactionSummary $summary): void
    {
        $this->summaries[$this->key($summary->chatId, $summary->rootMessageId)] = $summary;
    }

    public function findLatestForChain(int $chatId, int $rootMessageId): ?CompactionSummary
    {
        return $this->summaries[$this->key($chatId, $rootMessageId)] ?? null;
    }

    public function clearForChain(int $chatId, int $rootMessageId): void
    {
        unset($this->summaries[$this->key($chatId, $rootMessageId)]);
    }

    private function key(int $chatId, int $rootMessageId): string
    {
        return "$chatId:$rootMessageId";
    }
}
