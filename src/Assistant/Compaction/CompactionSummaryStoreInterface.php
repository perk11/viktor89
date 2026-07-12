<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Compaction;

interface CompactionSummaryStoreInterface
{
    /**
     * Persist (or replace) the compaction stored for a reply chain, identified
     * by the pair (chatId, rootMessageId).
     */
    public function store(CompactionSummary $summary): void;

    /**
     * Returns the most recent compaction for the reply chain, or null when none exists.
     */
    public function findLatestForChain(int $chatId, int $rootMessageId): ?CompactionSummary;

    /**
     * Removes any stored compaction for the reply chain.
     */
    public function clearForChain(int $chatId, int $rootMessageId): void;
}
