<?php

namespace Perk11\Viktor89\Repository;

use Perk11\Viktor89\Database;
use Perk11\Viktor89\MessageMetadata;
use SQLite3Stmt;

class MessageMetadataRepository
{
    private SQLite3Stmt $upsertStatement;
    private SQLite3Stmt $selectStatement;

    public function __construct(private readonly Database $database)
    {
        $sqlite = $this->database->sqlite3Database;
        $this->upsertStatement = $sqlite->prepare(
            'INSERT INTO message_metadata (chat_id, message_id, model, system_prompt, persona_id, caption)
             VALUES (:chat_id, :message_id, :model, :system_prompt, :persona_id, :caption)
             ON CONFLICT(chat_id, message_id) DO UPDATE SET
                 model = :model,
                 system_prompt = :system_prompt,
                 persona_id = :persona_id,
                 caption = :caption'
        );
        $this->selectStatement = $sqlite->prepare(
            'SELECT * FROM message_metadata WHERE chat_id = :chat_id AND message_id = :message_id'
        );
    }

    public function upsert(MessageMetadata $metadata): void
    {
        $this->upsertStatement->bindValue(':chat_id', $metadata->chatId);
        $this->upsertStatement->bindValue(':message_id', $metadata->messageId);
        $this->upsertStatement->bindValue(':model', $metadata->model);
        $this->upsertStatement->bindValue(':system_prompt', $metadata->systemPrompt);
        $this->upsertStatement->bindValue(':persona_id', $metadata->personaId, SQLITE3_INTEGER);
        $this->upsertStatement->bindValue(':caption', $metadata->caption);
        $this->upsertStatement->execute();
        $this->upsertStatement->reset();
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
