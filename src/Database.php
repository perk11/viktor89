<?php

namespace Perk11\Viktor89;

use Longman\TelegramBot\Entities\Message;
use SQLite3;

class Database
{
    private SQLite3 $sqlite3Database;

    private \SQLite3Stmt $insertMessageStatement;

    private \SQLite3Stmt|false $selectMessageStatement;


    public function __construct(string $name)
    {
        $databaseDir = dirname(__DIR__) . '/data';
        if (!@mkdir($databaseDir) && !is_dir($databaseDir)) {
            throw new \RuntimeException(sprintf('Failed to create directory "%s"', $databaseDir));
        }
        $this->sqlite3Database = new SQLite3($databaseDir . "/" . $name);
        $this->sqlite3Database->query(file_get_contents(__DIR__ . '/db-structure.sql'));
        $this->insertMessageStatement = $this->sqlite3Database->prepare(
            'INSERT INTO message (chat_id, id, message_thread_id, user_id, `date`, reply_to_message, username, message_text) VALUES (:chat_id, :id, :message_thread_id, :user_id, :date, :reply_to_message, :username, :message_text)'
        );
        $this->selectMessageStatement = $this->sqlite3Database->prepare(
            'SELECT * FROM message WHERE id = :id AND chat_id = :chat_id'
        );

    }

    public function logMessage(Message $message): void
    {
        $this->logInternalMessage(InternalMessage::fromTelegramMessage($message));
    }

    public function logInternalMessage(InternalMessage $message): void
    {
        $this->insertMessageStatement->bindValue(':id', $message->id);
        $this->insertMessageStatement->bindValue(':message_thread_id', $message->messageThreadId);
        $this->insertMessageStatement->bindValue(':user_id', $message->userId);
        $this->insertMessageStatement->bindValue(':date', $message->date);
        $this->insertMessageStatement->bindValue(':reply_to_message', $message->replyToMessageId);
        $this->insertMessageStatement->bindValue(':username', $message->userName);
        $this->insertMessageStatement->bindValue(':chat_id', $message->chatId);
        $this->insertMessageStatement->bindValue(':message_text', $message->messageText);


        $this->insertMessageStatement->execute();
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
                throw new \LogicException("Invalid value type passed for excluded id");
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
}
