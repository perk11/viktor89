<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Compaction;

/**
 * Immutable snapshot of a compaction persisted for a chat: the generated
 * summary plus the id of the newest message that was folded into it.
 * Keyed by (chatId, rootMessageId) so independent reply chains within the
 * same group chat keep separate summaries.
 */
final class CompactionSummary
{
    public function __construct(
        public readonly int $chatId,
        public readonly int $rootMessageId,
        public readonly string $summary,
        public readonly int $lastSummarizedMessageId,
        public readonly int $createdAt,
    ) {
    }
}
