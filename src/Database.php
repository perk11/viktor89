<?php

namespace Perk11\Viktor89;

use Longman\TelegramBot\Entities\Message;
use SQLite3;

class Database
{
    private SQLite3 $sqlite3Database;

    private \SQLite3Stmt $insertMessageStatement;

    private \SQLite3Stmt|false $selectMessageStatement;

    private \SQLite3Stmt|false $findNPreviousMessagesInChatStatement;

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
        $this->findNPreviousMessagesInChatStatement = $this->sqlite3Database->prepare(
            'SELECT * FROM message WHERE chat_id = :chat_id AND id<:message_id ORDER BY id DESC LIMIT :limit'
        );
    }

    public function logMessage(Message $message): void
    {
        $this->insertMessageStatement->bindValue(':id', $message->getMessageId());
        $this->insertMessageStatement->bindValue(':message_thread_id', $message->getMessageThreadId());
        $this->insertMessageStatement->bindValue(':user_id', $message->getFrom()->getId());
        $this->insertMessageStatement->bindValue(':date', $message->getDate());
        $this->insertMessageStatement->bindValue(':reply_to_message', $message->getReplyToMessage()?->getMessageId());
        $userName = $message->getFrom()->getFirstName();
        if ($message->getFrom()->getLastName() !== null) {
            $userName .= ' ' . $message->getFrom()->getLastName();
        }
        $this->insertMessageStatement->bindValue(':username', $userName);
        $this->insertMessageStatement->bindValue(':chat_id', $message->getChat()->getId());
        $this->insertMessageStatement->bindValue(':message_text', $message->getText());


        $this->insertMessageStatement->execute();
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

    public function findNPreviousMessagesInChat(int $chatId, int $messageId, int $limit): array
    {
        $this->findNPreviousMessagesInChatStatement->bindValue(':chat_id', $chatId);
        $this->findNPreviousMessagesInChatStatement->bindValue(':message_id', $messageId);
        $this->findNPreviousMessagesInChatStatement->bindValue(':limit', $limit);

        $resultingMessages = [];
        $queryResult = $this->findNPreviousMessagesInChatStatement->execute();
        while ($result = $queryResult->fetchArray(SQLITE3_ASSOC)) {
            $resultingMessages[] = InternalMessage::fromSqliteAssoc($result);
        }

        return $resultingMessages;
    }
}
