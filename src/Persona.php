<?php

namespace Perk11\Viktor89;

class Persona
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $systemPrompt,
        public readonly int $userId,
        public readonly string $userName,
        public readonly int $createdAt,
    ) {
    }

    public static function fromSqliteAssoc(array $result): self
    {
        return new self(
            id: (int) $result['id'],
            name: $result['name'],
            systemPrompt: $result['system_prompt'],
            userId: (int) $result['user_id'],
            userName: $result['username'],
            createdAt: (int) $result['created_at'],
        );
    }
}
