<?php

namespace Perk11\Viktor89\Repository;

use Perk11\Viktor89\Database;

class ChatSummaryRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function getLastChatSummaryDate(int $chatId): ?int
    {
        $fetchMessagesStatement = $this->database->sqlite3Database->prepare(
            "SELECT date FROM chat_summary WHERE chat_id = :chat_id ORDER BY date DESC LIMIT 1"
        );
        $fetchMessagesStatement->bindValue(':chat_id', $chatId);
        $result = $fetchMessagesStatement->execute();

        $resultArray =  $result->fetchArray(SQLITE3_NUM);
        if ($resultArray === false) {
            return null;
        }
        return $resultArray[0];
    }

    public function recordChatSummary(int $chatId, string $summary): void
    {
        $statement = $this->database->sqlite3Database->prepare(
            'INSERT INTO chat_summary (chat_id, summary, date) VALUES (:chat_id, :summary, :date)'
        );

        $statement->bindValue(':chat_id', $chatId);
        $statement->bindValue(':summary', $summary);
        $statement->bindValue(':date', time());
        $statement->execute();
    }
}
