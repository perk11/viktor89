<?php

namespace Perk11\Viktor89\Repository;

use Perk11\Viktor89\Database;

class SystemVariableRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function readSystemVariable(string $variableName): ?string
    {
        $fetchMessagesStatement = $this->database->sqlite3Database->prepare(
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
        $fetchMessagesStatement = $this->database->sqlite3Database->prepare(
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
}
