<?php

namespace Perk11\Viktor89\Repository;

use Perk11\Viktor89\Database;
use Perk11\Viktor89\Persona;

class PersonaRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function addPersona(string $name, string $systemPrompt, int $userId, string $userName): void
    {
        $statement = $this->database->sqlite3Database->prepare(
            'INSERT INTO persona (name, system_prompt, user_id, username, created_at)
                    VALUES (:name, :system_prompt, :user_id, :username, :created_at)'
        );
        $statement->bindValue(':name', $name);
        $statement->bindValue(':system_prompt', $systemPrompt);
        $statement->bindValue(':user_id', $userId);
        $statement->bindValue(':username', $userName);
        $statement->bindValue(':created_at', time());
        $statement->execute();
    }

    public function deletePersonaByName(string $name): void
    {
        $statement = $this->database->sqlite3Database->prepare('DELETE FROM persona WHERE name = :name');
        $statement->bindValue(':name', $name);
        $statement->execute();
    }

    public function findPersonaByName(string $name): ?Persona
    {
        $statement = $this->database->sqlite3Database->prepare('SELECT * FROM persona WHERE name = :name');
        $statement->bindValue(':name', $name);
        $result = $statement->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        if ($row === false) {
            return null;
        }

        return Persona::fromSqliteAssoc($row);
    }
    public function findPersonaById(int $id): ?Persona
    {
        $statement = $this->database->sqlite3Database->prepare('SELECT * FROM persona WHERE id = :id');
        $statement->bindValue(':id', $id);
        $result = $statement->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        if ($row === false) {
            return null;
        }

        return Persona::fromSqliteAssoc($row);
    }

    /** @return Persona[] */
    public function findAllPersonas(): array
    {
        $statement = $this->database->sqlite3Database->prepare('SELECT * FROM persona ORDER BY name');
        $result = $statement->execute();
        $personas = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $personas[] = Persona::fromSqliteAssoc($row);
        }

        return $personas;
    }

    public function countPersonasByUserId(int $userId): int
    {
        $statement = $this->database->sqlite3Database->prepare('SELECT COUNT(1) FROM persona WHERE user_id = :user_id');
        $statement->bindValue(':user_id', $userId);
        $result = $statement->execute();

        return (int) $result->fetchArray(SQLITE3_NUM)[0];
    }
}
