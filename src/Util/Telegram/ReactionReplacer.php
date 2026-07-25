<?php

namespace Perk11\Viktor89\Util\Telegram;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class ReactionReplacer
{
    public function __construct(
        private readonly ReactionDeleter $reactionDeleter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function deleteOrReplaceWith(int $chatId, int $messageId, string $emoji): void
    {
        if ($chatId>0 || !$this->reactionDeleter->delete($chatId, $messageId)) {
            $this->logger->log(LogLevel::INFO, 'Failed to delete message reaction, falling back to emoji reaction');
            ReactionSetter::setReaction($chatId, $messageId, $emoji);
        }
    }
}
