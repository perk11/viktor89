<?php

namespace Perk11\Viktor89;

use Longman\TelegramBot\Entities\Message;
use SQLite3;

class Database
{
    private SQLite3 $sqlite3Database;
    private \SQLite3Stmt $insertMessageStatement;
    public function __construct(string $name)
    {
        $databaseDir = dirname(__DIR__) . '/data';
        if (!@mkdir($databaseDir ) && !is_dir($databaseDir )) {
            throw new \RuntimeException(sprintf('Failed to create directory "%s"', $databaseDir));
        }
        $this->sqlite3Database = new SQLite3($databaseDir . "/" . $name);
        $this->sqlite3Database->query(file_get_contents(__DIR__ . '/db-structure.sql'));
        $this->insertMessageStatement = $this->sqlite3Database->prepare('INSERT INTO message (chat_id, id, message_thread_id, user_id, `date`, reply_to_message, username, message_text) VALUES (:chat_id, :id, :message_thread_id, :user_id, :date, :reply_to_message, :username, :message_text)');
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
        $this->insertMessageStatement->bindValue(':message_text', $message->getText());


        $this->insertMessageStatement->execute();
    }
}
