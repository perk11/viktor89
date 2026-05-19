<?php

namespace Perk11\Viktor89\Util\Telegram;

use Longman\TelegramBot\Request;

class ReactionDeleter
{
    public function __construct(
        private readonly int $telegramBotId,
    ) {
    }

    public function delete(int $chatId, int $messageId): bool
    {
        $deleteResultString = Request::execute('deleteMessageReaction', [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'user_id'    => $this->telegramBotId,
        ]);
        echo "Deleting message reaction result: $deleteResultString\n";

        $deleteResult = json_decode($deleteResultString, false);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "Failed to parse result of deleting message reaction " . json_last_error_msg() . "\n";

            return false;
        }

        return property_exists($deleteResult, 'ok') && $deleteResult->ok === true;
    }
}
