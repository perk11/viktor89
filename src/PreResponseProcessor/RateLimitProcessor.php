<?php

namespace Perk11\Viktor89\PreResponseProcessor;

use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Request;
use Perk11\Viktor89\Database;

class RateLimitProcessor implements PreResponseProcessor
{
    public function __construct(
        private readonly Database $database,
        private readonly array $rateLimitByChatId,
    ) {
    }

    public function process(Message $message): false|string|null
    {
        if ($message->getFrom() === null) {
            return false;
        }
        $chatId = $message->getChat()->getId();
        $userId = $message->getFrom()->getId();
        if (!array_key_exists($chatId, $this->rateLimitByChatId)) {
            return false;
        }
        $limit = $this->rateLimitByChatId[$message->getChat()->getId()];
        $priorMessages = $this->database->countMessagesInChatFromBotToUserInLast24Hours(
            $chatId,
            $userId,
        );

        if ($priorMessages < $limit) {
            return false;
        }
        echo "$priorMessages already sent by user {$userId} in chat $chatId, sending reaction instead of message\n";
        Request::execute('setMessageReaction', [
            'chat_id'    => $chatId,
            'message_id' => $message->getMessageId(),
            'reaction'   => [[
                'type'  => 'emoji',
                'emoji' => '🙊',
            ]],
        ]);

        return null;
    }
}
