<?php

namespace Perk11\Viktor89\JoinQuiz;

class KickQueueItem
{
    public function __construct(

        public readonly int $chatId,
        public readonly int $userId,
        public readonly int $pollId,
        public readonly int $joinMessageId,
        public readonly array $messagesToDelete,
        public readonly ?int $kickTime,
    ) {
    }

    public static function fromSqliteAssoc(array $result): self
    {
        return new self(
            chatId:        $result['chat_id'],
            userId:        $result['user_id'],
            pollId:        $result['poll_id'],
            joinMessageId: $result['join_message_id'],
            messagesToDelete: explode(',', $result['messages_to_delete']),
            kickTime:      $result['kick_time'],
        );
    }
}
