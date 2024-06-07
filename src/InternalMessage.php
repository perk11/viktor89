<?php

namespace Perk11\Viktor89;

use Longman\TelegramBot\Entities\Message;

class InternalMessage
{
    public int $id;

    public ?int $messageThreadId = null;

    public int $userId;

    public $date;

    public ?int $replyToMessageId = null;

    public string $userName;

    public string $messageText;

    public int $chatId;
    public static function fromSqliteAssoc(array $result): self
    {
        $message = new self();
        $message->id = $result['id'];
        $message->messageThreadId = $result['message_thread_id'];
        $message->userId = $result['user_id'];
        $message->chatId = $result['chat_id'];
        $message->date = $result['date'];
        $message->replyToMessageId = $result['reply_to_message'];
        $message->userName = $result['username'];
        $message->messageText = $result['message_text'];

        return $message;
    }

    public static function fromTelegramMessage(Message $telegramMessage): self
    {
        $message = new self();
        $message->id = $telegramMessage->getMessageId();
        $message->messageThreadId = $telegramMessage->getMessageThreadId();
        $message->userId = $telegramMessage->getFrom()->getId();
        $message->date = $telegramMessage->getDate();
        $message->replyToMessageId = $telegramMessage->getReplyToMessage()?->getMessageId();
        $message->userName = $telegramMessage->getFrom()->getFirstName();
        if ($telegramMessage->getFrom()->getLastName() !== null) {
            $message->userName .= ' ' . $telegramMessage->getFrom()->getLastName();
        }
        $message->chatId = $telegramMessage->getChat()->getId();
        $message->messageText = $telegramMessage->getText();

        return $message;
    }
}
