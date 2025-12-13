<?php

namespace Perk11\Viktor89;

use LogicException;
use Longman\TelegramBot\Entities\Message;
use Perk11\Viktor89\JoinQuiz\KickQueueItem;
use Perk11\Viktor89\RateLimiting\BanTimeLeft;
use Perk11\Viktor89\RateLimiting\RateLimit;
use RuntimeException;
use SQLite3;
use SQLite3Stmt;

class Database
{
    public readonly SQLite3 $sqlite3Database;

    private SQLite3Stmt $insertMessageStatement;
    private SQLite3Stmt $updateMessageStatement;

    private SQLite3Stmt|false $selectMessageStatement;

    private SQLite3Stmt|false $readPreferencesStatement;
    private SQLite3Stmt|false $updatePreferencesStatement;


    public function __construct(private int $botUserId, string $name)
    {
        $databaseDir = dirname(__DIR__) . '/data';
        if (!@mkdir($databaseDir) && !is_dir($databaseDir)) {
            throw new RuntimeException(sprintf('Failed to create directory "%s"', $databaseDir));
        }
        $this->sqlite3Database = new SQLite3($databaseDir . "/" . $name);
        $this->sqlite3Database->busyTimeout(30000);
        $this->sqlite3Database->query(file_get_contents(__DIR__ . '/db-structure.sql'));
        $this->insertMessageStatement = $this->sqlite3Database->prepare(
            'INSERT INTO message (chat_id, id, type, message_thread_id, user_id, `date`, reply_to_message, username, message_text, photo_file_id, alt_text)
VALUES (:chat_id, :id, :type, :message_thread_id, :user_id, :date, :reply_to_message, :username, :message_text, :photo_file_id, :alt_text)
'
        );
        $this->updateMessageStatement = $this->sqlite3Database->prepare('UPDATE message SET alt_text = :alt_text WHERE id = :id AND chat_id = :chat_id');

        $this->selectMessageStatement = $this->sqlite3Database->prepare(
            'SELECT * FROM message WHERE id = :id AND chat_id = :chat_id'
        );
        $this->readPreferencesStatement = $this->sqlite3Database->prepare(
            'SELECT preferences FROM user_preferences WHERE user_id = :user_id'
        );
        $this->updatePreferencesStatement = $this->sqlite3Database->prepare(
            'INSERT INTO user_preferences (user_id, preferences) VALUES (:user_id, :preferences) ON CONFLICT DO UPDATE SET preferences = :preferences'
        );
    }

    public function logMessage(Message $message): void
    {
        $this->logInternalMessage(InternalMessage::fromTelegramMessage($message));
    }

    public function logInternalMessage(InternalMessage $message): void
    {
        if ($message->isSaved) {
            $statement = $this->updateMessageStatement;
        } else {
            $statement = $this->insertMessageStatement;
            //these values are not supported in updateMessageStatement
            $statement->bindValue(':message_thread_id', $message->messageThreadId);
            $statement->bindValue(':user_id', $message->userId);
            $statement->bindValue(':date', $message->date);
            $statement->bindValue(':reply_to_message', $message->replyToMessageId);
            $statement->bindValue(':username', $message->userName);
            $statement->bindValue(':message_text', $message->messageText);
            $statement->bindValue(':photo_file_id', $message->photoFileId);
            $statement->bindValue(':type', $message->type);
        }
        $statement->bindValue(':id', $message->id);
        $statement->bindValue(':chat_id', $message->chatId);
        $statement->bindValue(':alt_text', $message->altText);

        $statement->execute();
        $message->isSaved = true;

    }

    public function findMessageByIdInChat(int $id, int $chatId): ?InternalMessage
    {
        $this->selectMessageStatement->bindValue(':id', $id);
        $this->selectMessageStatement->bindValue(':chat_id', $chatId);
        $result = $this->selectMessageStatement->execute()->fetchArray(SQLITE3_ASSOC);

        if ($result === false) {
            return null;
        }

        return InternalMessage::fromSqliteAssoc($result);
    }

    public function findNPreviousMessagesInChat(int $chatId, int $messageId, int $limit, array $excludedIds): array
    {
        foreach ($excludedIds as $excludedId) {
            if (!is_int($excludedId)) {
                throw new LogicException("Invalid value type passed for excluded id");
            }
        }
        $excludedIdsString = implode(',', $excludedIds);
        $findNPreviousMessagesInChatStatement = $this->sqlite3Database->prepare(
            "SELECT * FROM message WHERE chat_id = :chat_id AND id<:message_id 
                      AND id NOT in ($excludedIdsString)
                      ORDER BY id DESC LIMIT :limit"
        );
        $findNPreviousMessagesInChatStatement->bindValue(':chat_id', $chatId);
        $findNPreviousMessagesInChatStatement->bindValue(':message_id', $messageId);
        $findNPreviousMessagesInChatStatement->bindValue(':limit', $limit);

        $resultingMessages = [];
        $queryResult = $findNPreviousMessagesInChatStatement->execute();
        while ($result = $queryResult->fetchArray(SQLITE3_ASSOC)) {
            $resultingMessages[] = InternalMessage::fromSqliteAssoc($result);
        }

        return $resultingMessages;
    }

    public function countMessagesInChatFromBotToUserInLast24Hours(int $chatId, int $userId): int
    {
        $countMessagesByUserIn24HoursStatement = $this->sqlite3Database->prepare(
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
        $countMessagesByUserIn24HoursStatement->bindValue(':bot_user_id', $this->botUserId);
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

        $stmt = $this->sqlite3Database->prepare($sql);

        foreach ($chatRateLimits as $limit) {
            $stmt->bindValue(':chat_id', $limit->chatId, SQLITE3_INTEGER);
            $stmt->bindValue(':bot_user_id', $this->botUserId, SQLITE3_INTEGER);
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

    /** @return InternalMessage[] */
    public function findMessagesSentAfterTimestampInChat(int $chatId, int $startTimeStamp): array
    {
        $fetchMessagesStatement = $this->sqlite3Database->prepare(
            "SELECT * FROM message WHERE chat_id = :chat_id AND message.date>:start_timestamp
                      AND message.type='text'
                      ORDER BY id"
        );
        $fetchMessagesStatement->bindValue(':chat_id', $chatId);
        $fetchMessagesStatement->bindValue(':start_timestamp', $startTimeStamp);

        $resultingMessages = [];
        $queryResult = $fetchMessagesStatement->execute();
        while ($result = $queryResult->fetchArray(SQLITE3_ASSOC)) {
            $resultingMessages[] = InternalMessage::fromSqliteAssoc($result);
        }

        return $resultingMessages;
    }

    public function readUserPreference(int $userId, string $key): array|string|bool|null
    {
        $preferences = $this->readPreferencesArray($userId);

        return $preferences[$key] ?? null;
    }

    public function writeUserPreference(int $userId, string $key, object|string|bool|null $value): void
    {
        $preferences = $this->readPreferencesArray($userId);
        $preferences[$key] = $value;

        $this->updatePreferencesStatement->bindValue(':user_id', $userId);
        $this->updatePreferencesStatement->bindValue(':preferences', json_encode($preferences, JSON_THROW_ON_ERROR & JSON_UNESCAPED_UNICODE));
        $this->updatePreferencesStatement->execute();
    }

    public function readPreferencesArray(int $userId): ?array
    {
        $this->readPreferencesStatement->bindValue(':user_id', $userId);
        $result = $this->readPreferencesStatement->execute();
        $preferencesArray = $result->fetchArray(SQLITE3_ASSOC);
        if ($preferencesArray === false) {
            return [];
        }

        return json_decode($preferencesArray['preferences'], true, flags: JSON_THROW_ON_ERROR);

    }

    public function getLastChatSummaryDate(int $chatId): ?int
    {
        $fetchMessagesStatement = $this->sqlite3Database->prepare(
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
        $statement = $this->sqlite3Database->prepare(
            'INSERT INTO chat_summary (chat_id, summary, date) VALUES (:chat_id, :summary, :date)'
        );

        $statement->bindValue(':chat_id', $chatId);
        $statement->bindValue(':summary', $summary);
        $statement->bindValue(':date', time());
        $statement->execute();
    }

    public function readSystemVariable(string $variableName): ?string
    {
        $fetchMessagesStatement = $this->sqlite3Database->prepare(
            "SELECT value FROM system_variable WHERE name = :variable_name"
        );
        $fetchMessagesStatement->bindValue('variable_name', $variableName);
        $result = $fetchMessagesStatement->execute();

        $resultArray = $result->fetchArray(SQLITE3_NUM);
        if ($resultArray === false) {
            return null;
        }

        return $resultArray[0];
    }

    public function writeSystemVariable(string $variableName, string $variableValue): void
    {
        $fetchMessagesStatement = $this->sqlite3Database->prepare(
            "
INSERT INTO system_variable (name,value,updated_at) VALUES(:variable_name, :variable_value, CURRENT_TIMESTAMP)
ON CONFLICT(name) DO UPDATE SET 
    value = :variable_value,
    updated_at = CURRENT_TIMESTAMP
"
        );
        $fetchMessagesStatement->bindValue('variable_name', $variableName);
        $fetchMessagesStatement->bindValue('variable_value', $variableValue);
        $fetchMessagesStatement->execute();
    }

    /** @return KickQueueItem[] */
    public function findPendingKickQueueItems(): array
    {
        $fetchMessagesStatement = $this->sqlite3Database->prepare(
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
        $fetchMessagesStatement = $this->sqlite3Database->prepare(
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
        $statement = $this->sqlite3Database->prepare(
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
            echo $this->sqlite3Database->lastErrorMsg();
            echo "\n";
        }
    }

    public function nullKickTime(int $pollId): void
    {
        $statement = $this->sqlite3Database->prepare(
            'UPDATE kick_queue SET kick_time = NULL WHERE poll_id = :poll_id'
        );

        $statement->bindValue(':poll_id', $pollId);
        if ($statement->execute() === false) {
            echo "Failed to execute null kick time update\n";
            echo $this->sqlite3Database->lastErrorMsg();
            echo "\n";
        }
    }

    public function findMissingPatches(array $links): array
    {

        $placeholders = [];
        foreach ($links as $index => $link) {
            $placeholders[] = ':link' . $index;
        }

        $sql = 'SELECT link FROM patches WHERE link IN (' . implode(',', $placeholders) . ')';
        $statement = $this->sqlite3Database->prepare($sql);

        foreach ($links as $index => $link) {
            $statement->bindValue(':link' . $index, $link, SQLITE3_TEXT);
        }

        $executionResult = $statement->execute();
        $existingLinks = [];

        while ($link = $executionResult->fetchArray(SQLITE3_NUM)) {
            $existingLinks[] = $link[0];
        }

        $missingLinks = [];
        foreach ($links as $link) {
            if (!in_array($link, $existingLinks, true)) {
                $missingLinks[] = $link;
            }
        }

        return $missingLinks;
    }

    public function insertPatch(string $link): void
    {
        $statement = $this->sqlite3Database->prepare(' INSERT INTO patches (link) VALUES (:link)');
        $statement->bindValue(':link', $link);
        $statement->execute();
    }

    public function readFileCacheNameById(string $fileId): ?string
    {
        $statement = $this->sqlite3Database->prepare('SELECT file_name FROM file_cache WHERE file_id = :file_id');
        $statement->bindValue(':file_id', $fileId);
        $result = $statement->execute();

        $resultArray = $result->fetchArray(SQLITE3_NUM);
        if ($resultArray === false) {
            return null;
        }
        return $resultArray[0];
    }
    public function readFileCacheNameBySha1(string $sha1): ?string
    {
        $statement = $this->sqlite3Database->prepare('SELECT file_name FROM file_cache WHERE sha1 = :sha1');
        $statement->bindValue(':sha1', $sha1);
        $result = $statement->execute();

        $resultArray = $result->fetchArray(SQLITE3_NUM);
        if ($resultArray === false) {
            return null;
        }
        return $resultArray[0];
    }

    public function writeFileCache(string $fileId, string $fileName, string $sha1): void
    {
        $statement = $this->sqlite3Database->prepare('INSERT INTO file_cache (file_id, file_name, sha1) VALUES (:file_id, :file_name, :sha1)');
        $statement->bindValue(':file_id', $fileId);
        $statement->bindValue(':file_name', $fileName);
        $statement->bindValue(':sha1', $sha1);
        $statement->execute();
    }
}
