<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Compaction;

use Perk11\Viktor89\Database;

/**
 * Persists a single "current" compaction per reply chain in the
 * `context_compaction` table, keyed by (chat_id, root_message_id). Only the
 * newest row per chain is kept; storing a new one replaces the previous so the
 * summary always reflects the latest compacted state.
 */
final class SqliteCompactionSummaryStore implements CompactionSummaryStoreInterface
{
    public function __construct(private readonly Database $database)
    {
    }

    public function store(CompactionSummary $summary): void
    {
        $sqlite = $this->database->sqlite3Database;
        $sqlite->exec('BEGIN');
        try {
            $delete = $sqlite->prepare(
                'DELETE FROM context_compaction WHERE chat_id = :chat_id AND root_message_id = :root_message_id'
            );
            $delete->bindValue(':chat_id', $summary->chatId, SQLITE3_INTEGER);
            $delete->bindValue(':root_message_id', $summary->rootMessageId, SQLITE3_INTEGER);
            $delete->execute();

            $insert = $sqlite->prepare(
                'INSERT INTO context_compaction (chat_id, root_message_id, summary, last_summarized_message_id, created_at)
                 VALUES (:chat_id, :root_message_id, :summary, :last_summarized_message_id, :created_at)'
            );
            $insert->bindValue(':chat_id', $summary->chatId, SQLITE3_INTEGER);
            $insert->bindValue(':root_message_id', $summary->rootMessageId, SQLITE3_INTEGER);
            $insert->bindValue(':summary', $summary->summary);
            $insert->bindValue(':last_summarized_message_id', $summary->lastSummarizedMessageId, SQLITE3_INTEGER);
            $insert->bindValue(':created_at', $summary->createdAt, SQLITE3_INTEGER);
            $insert->execute();
            $sqlite->exec('COMMIT');
        } catch (\Throwable $e) {
            $sqlite->exec('ROLLBACK');
            throw $e;
        }
    }

    public function findLatestForChain(int $chatId, int $rootMessageId): ?CompactionSummary
    {
        $statement = $this->database->sqlite3Database->prepare(
            'SELECT chat_id, root_message_id, summary, last_summarized_message_id, created_at
             FROM context_compaction
             WHERE chat_id = :chat_id AND root_message_id = :root_message_id
             ORDER BY created_at DESC
             LIMIT 1'
        );
        $statement->bindValue(':chat_id', $chatId, SQLITE3_INTEGER);
        $statement->bindValue(':root_message_id', $rootMessageId, SQLITE3_INTEGER);
        $result = $statement->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        if ($row === false) {
            return null;
        }

        return new CompactionSummary(
            (int) $row['chat_id'],
            (int) $row['root_message_id'],
            (string) $row['summary'],
            (int) $row['last_summarized_message_id'],
            (int) $row['created_at'],
        );
    }

    public function clearForChain(int $chatId, int $rootMessageId): void
    {
        $statement = $this->database->sqlite3Database->prepare(
            'DELETE FROM context_compaction WHERE chat_id = :chat_id AND root_message_id = :root_message_id'
        );
        $statement->bindValue(':chat_id', $chatId, SQLITE3_INTEGER);
        $statement->bindValue(':root_message_id', $rootMessageId, SQLITE3_INTEGER);
        $statement->execute();
    }
}
