<?php

namespace Perk11\Viktor89\Util\Telegram;

use Longman\TelegramBot\Request;

class ReactionReplacer
{
    public function __construct(
        private readonly ReactionDeleter $reactionDeleter,
    ) {
    }

    public function deleteOrReplaceWith(int $chatId, int $messageId, string $emoji): void
    {
        if ($chatId>0 || !$this->reactionDeleter->delete($chatId, $messageId)) {
            echo "Failed to delete message reaction, falling back to emoji reaction\n";
            Request::execute('setMessageReaction', [
                'chat_id'    => $chatId,
                'message_id' => $messageId,
                'reaction'   => [
                    [
                        'type'  => 'emoji',
                        'emoji' => $emoji,
                    ],
                ],
            ]);
        }
    }
}
