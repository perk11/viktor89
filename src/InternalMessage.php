<?php

namespace Perk11\Viktor89;

class InternalMessage
{
    public int $id;

    public ?int $messageThreadId;

    public int $userId;

    public $date;

    public ?int $replyToMessageId;

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
}
