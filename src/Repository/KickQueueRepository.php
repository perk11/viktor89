<?php

namespace Perk11\Viktor89\Repository;

use Perk11\Viktor89\Database;
use Perk11\Viktor89\JoinQuiz\KickQueueItem;

class KickQueueRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @return KickQueueItem[] */
    public function findPendingKickQueueItems(): array
    {
        $fetchMessagesStatement = $this->database->sqlite3Database->prepare(
            "SELECT * FROM kick_queue WHERE kick_time < unixepoch(CURRENT_TIMESTAMP)"
        );
        $result = $fetchMessagesStatement->execute();

        $items = [];
        while ($resultArray = $result->fetchArray(SQLITE3_ASSOC)) {
            $items[] = KickQueueItem::fromSqliteAssoc($resultArray);
        }
        return $items;
    }

    public function findKickQueueItemByPollId(int $pollId): ?KickQueueItem
    {
        $fetchMessagesStatement = $this->database->sqlite3Database->prepare(
            "SELECT * FROM kick_queue WHERE poll_id = :poll_id"
        );
        $fetchMessagesStatement->bindValue(':poll_id', $pollId);
        $result = $fetchMessagesStatement->execute();
        $resultArray = $result->fetchArray(SQLITE3_ASSOC);
        if ($resultArray === false) {
            return null;
        }

        return KickQueueItem::fromSqliteAssoc($resultArray);
    }

    public function insertKickQueueItem(KickQueueItem $kickQueueItem): void
    {
        $statement = $this->database->sqlite3Database->prepare(
            'INSERT INTO kick_queue (
                        chat_id,
                        user_id,
                        poll_id, 
                        join_message_id,
                        kick_time,
                        messages_to_delete
                    )
                    VALUES (
                        :chat_id,
                        :user_id,
                        :poll_id,
                        :join_message_id,
                        :kick_time,
                        :messages_to_delete
                    )'
        );

        $statement->bindValue(':chat_id', $kickQueueItem->chatId);
        $statement->bindValue(':user_id', $kickQueueItem->userId);
        $statement->bindValue(':poll_id', $kickQueueItem->pollId);
        $statement->bindValue(':join_message_id', $kickQueueItem->joinMessageId);
        $statement->bindValue(':kick_time', $kickQueueItem->kickTime);
        $statement->bindValue(':messages_to_delete', implode(',', $kickQueueItem->messagesToDelete));
        if ($statement->execute() === false) {
            echo "Failed to insert into kick queue\n";
            echo $this->database->sqlite3Database->lastErrorMsg();
            echo "\n";
        }
    }

    public function nullKickTime(int $pollId): void
    {
        $statement = $this->database->sqlite3Database->prepare(
            'UPDATE kick_queue SET kick_time = NULL WHERE poll_id = :poll_id'
        );

        $statement->bindValue(':poll_id', $pollId);
        if ($statement->execute() === false) {
            echo "Failed to execute null kick time update\n";
            echo $this->database->sqlite3Database->lastErrorMsg();
            echo "\n";
        }
    }
}
