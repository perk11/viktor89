<?php

namespace Perk11\Viktor89;

use Perk11\Viktor89\Assistant\Compaction\CompactionSummaryStoreInterface;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;

/**
 * /new — restart the conversation context in private chats: reading history
 * for the LLM aborts at the /new command, so older messages are not included.
 */
class ContextResetProcessor implements MessageChainProcessor
{
    public const string COMMAND = '/new';

    /**
     * Whether a stored message text is a /new invocation. Matches the same
     * complete-command-token rule as CommandBasedResponderTrigger, so
     * `/new@bot` counts but `/newyear` does not.
     */
    public static function isContextResetCommandText(string $text): bool
    {
        return preg_match('/^' . preg_quote(self::COMMAND, '/') . '\b/', $text) === 1;
    }

    public function __construct(private readonly CompactionSummaryStoreInterface $compactionSummaryStore)
    {
    }

    public function processMessageChain(MessageChain $messageChain, ProgressUpdateCallback $progressUpdateCallback): ProcessingResult
    {
        $last = $messageChain->last();

        // Private chats have positive ids; groups, supergroups and channels are negative.
        if ($last->chatId < 0) {
            return new ProcessingResult(
                InternalMessage::asResponseTo($last, 'Эта команда работает только в личных сообщениях.'),
                true,
            );
        }

        // Private chats keep a single compaction rooted at message id 0; without
        // clearing it, the old conversation summary would survive the reset.
        $this->compactionSummaryStore->clearForChain($last->chatId, 0);

        return new ProcessingResult(
            InternalMessage::asResponseTo($last, 'Контекст сброшен, предыдущие сообщения больше не учитываются.'),
            true,
        );
    }
}
