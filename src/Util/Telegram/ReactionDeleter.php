<?php

namespace Perk11\Viktor89\Util\Telegram;

use Longman\TelegramBot\Request;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class ReactionDeleter
{
    public function __construct(
        private readonly int $telegramBotId,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function delete(int $chatId, int $messageId): bool
    {
        $deleteResultString = Request::execute('deleteMessageReaction', [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'user_id'    => $this->telegramBotId,
        ]);
        $this->logger->log(LogLevel::DEBUG, "Deleting message reaction result: $deleteResultString");

        $deleteResult = json_decode($deleteResultString, false);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->log(LogLevel::ERROR, 'Failed to parse result of deleting message reaction ' . json_last_error_msg());

            return false;
        }

        return property_exists($deleteResult, 'ok') && $deleteResult->ok === true;
    }
}
