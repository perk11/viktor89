<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Compaction;

use Perk11\Viktor89\MessageChain;

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

    /**
     * The chain root (first message id) is stable across messages within a
     * reply thread. Private chats are a single linear conversation, so they
     * share a sentinel root of 0 regardless of which message started a window.
     */
    public static function forMessageChain(?MessageChain $messageChain): ?self
    {
        if ($messageChain === null) {
            return null;
        }

        $last = $messageChain->last();
        $isPrivateChat = $last->chatId > 0;
        $rootMessageId = $isPrivateChat ? 0 : (int) ($messageChain->first()->id ?? 0);

        return new self($last->chatId, $rootMessageId);
    }
}
