<?php

namespace Perk11\Viktor89\Repository;

use LogicException;
use Longman\TelegramBot\Entities\Message;
use Perk11\Viktor89\Assistant\Tool\ToolCall;
use Perk11\Viktor89\Database;
use Perk11\Viktor89\InternalMessage;
use SQLite3Stmt;

class MessageRepository
{
    private SQLite3Stmt $insertMessageStatement;
    private SQLite3Stmt $updateMessageStatement;
    private SQLite3Stmt $insertToolCallStatement;
    private SQLite3Stmt $selectMessageStatement;

    public function __construct(private readonly Database $database)
    {
        $sqlite = $this->database->sqlite3Database;
        $this->insertMessageStatement = $sqlite->prepare(
            'INSERT INTO message (chat_id, id, type, message_thread_id, user_id, `date`, reply_to_message, username, message_text, photo_file_id, alt_text, reasoning)
VALUES (:chat_id, :id, :type, :message_thread_id, :user_id, :date, :reply_to_message, :username, :message_text, :photo_file_id, :alt_text, :reasoning)
'
        );
        $this->updateMessageStatement = $sqlite->prepare('UPDATE message SET alt_text = :alt_text WHERE id = :id AND chat_id = :chat_id');
        $this->insertToolCallStatement = $sqlite->prepare(
            'INSERT INTO tool_call (message_id, tool_call_id, tool_name, arguments, result, chat_id)
VALUES (:message_id, :tool_call_id, :tool_name, :arguments, :result, :chat_id)'
        );
        $this->selectMessageStatement = $sqlite->prepare(
            'SELECT * FROM message WHERE id = :id AND chat_id = :chat_id'
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
        if (!$message->isSaved) {
            $statement->bindValue(':reasoning', $message->reasoning);
        }

        $statement->execute();
        $message->isSaved = true;

        foreach ($message->toolCalls as $toolCall) {
            $this->logToolCall($message->id, $message->chatId, $toolCall);
        }
    }

    private function logToolCall(int $messageId, int $chatId, ToolCall $toolCall): void
    {
        $this->insertToolCallStatement->bindValue(':message_id', $messageId);
        $this->insertToolCallStatement->bindValue(':tool_call_id', $toolCall->id);
        $this->insertToolCallStatement->bindValue(':tool_name', $toolCall->name);
        $this->insertToolCallStatement->bindValue(':arguments', $toolCall->arguments);
        $this->insertToolCallStatement->bindValue(':result', $toolCall->result);
        $this->insertToolCallStatement->bindValue(':chat_id', $chatId);
        $this->insertToolCallStatement->execute();
    }

    public function findMessageByIdInChat(int $id, int $chatId): ?InternalMessage
    {
        $this->selectMessageStatement->bindValue(':id', $id);
        $this->selectMessageStatement->bindValue(':chat_id', $chatId);
        $result = $this->selectMessageStatement->execute()->fetchArray(SQLITE3_ASSOC);

        if ($result === false) {
            return null;
        }

        $message = InternalMessage::fromSqliteAssoc($result);
        $message->toolCalls = $this->findToolCallsByMessageId($id, $chatId);
        return $message;
    }

    /**
     * @return ToolCall[]
     */
    private function findToolCallsByMessageId(int $messageId, int $chatId): array
    {
        $statement = $this->database->sqlite3Database->prepare('SELECT * FROM tool_call WHERE message_id = :message_id AND chat_id = :chat_id');
        $statement->bindValue(':message_id', $messageId);
        $statement->bindValue(':chat_id', $chatId);
        $result = $statement->execute();
        $toolCalls = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $toolCalls[] = new ToolCall(
                $row['tool_call_id'],
                $row['tool_name'],
                $row['arguments'],
                $row['result'],
            );
        }
        return $toolCalls;
    }

    public function findNPreviousMessagesInChat(int $chatId, int $messageId, int $limit, array $excludedIds): array
    {
        foreach ($excludedIds as $excludedId) {
            if (!is_int($excludedId)) {
                throw new LogicException("Invalid value type passed for excluded id");
            }
        }
        $excludedIdsString = implode(',', $excludedIds);
        $findNPreviousMessagesInChatStatement = $this->database->sqlite3Database->prepare(
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
            $message = InternalMessage::fromSqliteAssoc($result);
            $message->toolCalls = $this->findToolCallsByMessageId($message->id, $message->chatId);
            $resultingMessages[] = $message;
        }

        return $resultingMessages;
    }

/**
     * Newest-first list of the last $limit messages authored by $userId in $chatId. Tool calls are not loaded —
     * only message_text is needed by the callers (roast/compliment/personality-card transcripts).
     *
     * @return InternalMessage[]
     */
    public function findLastMessagesByUserInChat(int $chatId, int $userId, int $limit): array
    {
        $statement = $this->database->sqlite3Database->prepare(
            'SELECT * FROM message WHERE chat_id = :chat_id AND user_id = :user_id ORDER BY id DESC LIMIT :limit'
        );
        $statement->bindValue(':chat_id', $chatId, SQLITE3_INTEGER);
        $statement->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $statement->bindValue(':limit', $limit, SQLITE3_INTEGER);

        $messages = [];
        $result = $statement->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $messages[] = InternalMessage::fromSqliteAssoc($row);
        }

        return $messages;
    }

    /** @return InternalMessage[] */
    public function findMessagesSentAfterTimestampInChat(int $chatId, int $startTimeStamp): array
    {
        $fetchMessagesStatement = $this->database->sqlite3Database->prepare(
            "SELECT * FROM message WHERE chat_id = :chat_id AND message.date>:start_timestamp
                      ORDER BY id"
        );
        $fetchMessagesStatement->bindValue(':chat_id', $chatId);
        $fetchMessagesStatement->bindValue(':start_timestamp', $startTimeStamp);

        $resultingMessages = [];
        $queryResult = $fetchMessagesStatement->execute();
        while ($result = $queryResult->fetchArray(SQLITE3_ASSOC)) {
            $message = InternalMessage::fromSqliteAssoc($result);
            $message->toolCalls = $this->findToolCallsByMessageId($message->id, $message->chatId);
            $resultingMessages[] = $message;
        }

        return $resultingMessages;
    }

    /**
     * @return array<int, array{user_id: int, username: string, message_count: int, text_count: int, sticker_count: int, other_count: int, word_count: int}>
     */
    public function findTopTalkersInChat(int $chatId, int $limit = 30, int $days = 30): array
    {
        $statement = $this->database->sqlite3Database->prepare(
            "SELECT user_id, username, COUNT(1) AS message_count,
                    SUM(CASE WHEN type = 'text' THEN 1 ELSE 0 END) AS text_count,
                    SUM(CASE WHEN type = 'sticker' THEN 1 ELSE 0 END) AS sticker_count,
                    SUM(CASE WHEN type NOT IN ('text', 'sticker') THEN 1 ELSE 0 END) AS other_count,
                    SUM(CASE WHEN type = 'text' THEN LENGTH(message_text) - LENGTH(REPLACE(message_text, ' ', '')) + 1 ELSE 0 END) AS word_count
FROM message
WHERE chat_id = :chat_id
  AND date > unixepoch(DATETIME(CURRENT_TIMESTAMP, '-' || :days || ' days'))
GROUP BY user_id, username
ORDER BY message_count DESC
LIMIT :limit"
        );
        $statement->bindValue(':chat_id', $chatId, SQLITE3_INTEGER);
        $statement->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $statement->bindValue(':days', $days, SQLITE3_INTEGER);

        $result = $statement->execute();
        $talkers = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $talkers[] = [
                'user_id' => (int)$row['user_id'],
                'username' => (string)$row['username'],
                'message_count' => (int)$row['message_count'],
                'text_count' => (int)$row['text_count'],
                'sticker_count' => (int)$row['sticker_count'],
                'other_count' => (int)$row['other_count'],
                'word_count' => (int)$row['word_count'],
            ];
        }

        return $talkers;
    }
}
