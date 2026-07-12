<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Compaction;

/**
 * Identifies a single reply chain within a chat. A group chat can contain
 * multiple independent threads, so a compaction is scoped to the chain root
 * rather than just the chat.
 */
final class CompactionKey
{
    public function __construct(
        public readonly int $chatId,
        public readonly int $rootMessageId,
    ) {
    }
}
