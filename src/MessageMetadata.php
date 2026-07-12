<?php

namespace Perk11\Viktor89;

/**
 * Generation metadata recorded for messages created by AI: the model that
 * produced the response, the system prompt that was used, the active persona id
 * (if any), and the caption (for images).
 */
class MessageMetadata
{
    public function __construct(
        public readonly int $chatId,
        public readonly int $messageId,
        public readonly ?string $model = null,
        public readonly ?string $systemPrompt = null,
        public readonly ?int $personaId = null,
        public readonly ?string $caption = null,
    ) {
    }

    public function hasAny(): bool
    {
        return $this->model !== null
            || $this->systemPrompt !== null
            || $this->personaId !== null
            || $this->caption !== null;
    }

    public static function fromSqliteAssoc(array $result): self
    {
        return new self(
            (int) $result['chat_id'],
            (int) $result['message_id'],
            $result['model'] ?? null,
            $result['system_prompt'] ?? null,
            $result['persona_id'] !== null ? (int) $result['persona_id'] : null,
            $result['caption'] ?? null,
        );
    }
}
