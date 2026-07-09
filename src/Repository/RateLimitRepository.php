<?php

namespace Perk11\Viktor89\Repository;

use Perk11\Viktor89\Database;
use Perk11\Viktor89\RateLimiting\BanTimeLeft;
use Perk11\Viktor89\RateLimiting\RateLimit;

class RateLimitRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function countMessagesInChatFromBotToUserInLast24Hours(int $chatId, int $userId): int
    {
        $countMessagesByUserIn24HoursStatement = $this->database->sqlite3Database->prepare(
            "SELECT count(1)
FROM message
         JOIN message source_message ON message.reply_to_message=source_message.id AND message.chat_id=source_message.chat_id
WHERE message.chat_id=:chat_id
 AND message.user_id=:bot_user_id
      AND source_message.user_id=:checked_user_id
AND message.date>unixepoch(DATETIME(CURRENT_TIMESTAMP, '-1 day'))"
        );
        $countMessagesByUserIn24HoursStatement->bindValue(':chat_id', $chatId);
        $countMessagesByUserIn24HoursStatement->bindValue(':checked_user_id', $userId);
        $countMessagesByUserIn24HoursStatement->bindValue(':bot_user_id', $this->database->botUserId);
        $result = $countMessagesByUserIn24HoursStatement->execute();

        return $result->fetchArray(SQLITE3_NUM)[0];
    }

    /**
     * The logic here is not well-tested, could be incorrect
     * @param RateLimit[] $chatRateLimits
     * @return BanTimeLeft[]  in the same order as $chatLimits
     */
    public function findRateLimitsByChat(array $chatRateLimits, int $userId): array
    {
        $results = [];
        $now = time();
        // A CTE that:
        //  - collects all bot→user replies in the last 24h
        //  - numbers them oldest→newest (message_rank)
        //  - carries the total count (total_messages)
        $sql = <<<SQL
WITH windowed_messages AS (
  SELECT
    message.date AS message_timestamp,
    ROW_NUMBER() OVER (ORDER BY message.date) AS message_rank,
    COUNT(1) OVER () AS total_messages
  FROM message
  JOIN message AS original_message
    ON message.reply_to_message = original_message.id
   AND message.chat_id          = original_message.chat_id
  WHERE message.chat_id        = :chat_id
    AND message.user_id        = :bot_user_id
    AND original_message.user_id = :checked_user_id
    AND message.date > unixepoch(DATETIME(CURRENT_TIMESTAMP, '-1 day'))
)
SELECT
  total_messages,
  MAX(
    CASE
      -- this is the message which, once it ages out,
      -- will drop us below the maxMessages threshold
      WHEN message_rank = total_messages - (:maxMessages - 1) THEN message_timestamp
    END
  ) AS cutoff_timestamp
FROM windowed_messages
SQL;

        $stmt = $this->database->sqlite3Database->prepare($sql);

        foreach ($chatRateLimits as $limit) {
            $stmt->bindValue(':chat_id', $limit->chatId, SQLITE3_INTEGER);
            $stmt->bindValue(':bot_user_id', $this->database->botUserId, SQLITE3_INTEGER);
            $stmt->bindValue(':checked_user_id', $userId, SQLITE3_INTEGER);
            $stmt->bindValue(':maxMessages', $limit->maxMessages, SQLITE3_INTEGER);

            $res = $stmt->execute();
            $row = $res->fetchArray(SQLITE3_ASSOC);

            $totalMessages = (int)($row['total_messages'] ?? 0);
            $cutoffTimestamp = isset($row['cutoff_timestamp'])
                ? (int)$row['cutoff_timestamp']
                : null;

            if ($totalMessages < $limit->maxMessages || $cutoffTimestamp === null) {
                // never hit the ceiling
                continue;
            }
            $elapsed = $now - $cutoffTimestamp;
            $timeLeft = max(0, 86400 - $elapsed);
            $results[] = new BanTimeLeft($limit->chatId, $timeLeft);
        }

        return $results;
    }
}
