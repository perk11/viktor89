<?php

namespace Perk11\Viktor89\Repository;

use Perk11\Viktor89\Database;
use Perk11\Viktor89\MessageMetadata;
use SQLite3Stmt;

class MessageMetadataRepository
{
    private SQLite3Stmt $insertStatement;
    private SQLite3Stmt $selectStatement;

    public function __construct(private readonly Database $database)
    {
        $sqlite = $this->database->sqlite3Database;
        $this->insertStatement = $sqlite->prepare(
            'INSERT INTO message_metadata (chat_id, message_id, model, system_prompt, persona_id, caption)
             VALUES (:chat_id, :message_id, :model, :system_prompt, :persona_id, :caption)'
        );
        $this->selectStatement = $sqlite->prepare(
            'SELECT * FROM message_metadata WHERE chat_id = :chat_id AND message_id = :message_id'
        );
    }

    public function insert(MessageMetadata $metadata): bool
    {
        $this->insertStatement->bindValue(':chat_id', $metadata->chatId);
        $this->insertStatement->bindValue(':message_id', $metadata->messageId);
        $this->insertStatement->bindValue(':model', $metadata->model);
        $this->insertStatement->bindValue(':system_prompt', $metadata->systemPrompt);
        $this->insertStatement->bindValue(':persona_id', $metadata->personaId, SQLITE3_INTEGER);
        $this->insertStatement->bindValue(':caption', $metadata->caption);
        $success = $this->insertStatement->execute();
        $this->insertStatement->reset();

        return $success !== false;
    }

    public function findByMessageIdInChat(int $messageId, int $chatId): ?MessageMetadata
    {
        $this->selectStatement->bindValue(':chat_id', $chatId);
        $this->selectStatement->bindValue(':message_id', $messageId);
        $result = $this->selectStatement->execute()->fetchArray(SQLITE3_ASSOC);
        $this->selectStatement->reset();

        if ($result === false) {
            return null;
        }

        return MessageMetadata::fromSqliteAssoc($result);
    }
}
