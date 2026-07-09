<?php

namespace Perk11\Viktor89\Repository;

use Perk11\Viktor89\Database;
use SQLite3Stmt;

class UserPreferenceRepository
{
    private SQLite3Stmt $readPreferencesStatement;
    private SQLite3Stmt $updatePreferencesStatement;

    public function __construct(private readonly Database $database)
    {
        $sqlite = $this->database->sqlite3Database;
        $this->readPreferencesStatement = $sqlite->prepare(
            'SELECT preferences FROM user_preferences WHERE user_id = :user_id'
        );
        $this->updatePreferencesStatement = $sqlite->prepare(
            'INSERT INTO user_preferences (user_id, preferences) VALUES (:user_id, :preferences) ON CONFLICT DO UPDATE SET preferences = :preferences'
        );
    }

    public function readUserPreference(int $userId, string $key): array|string|bool|null
    {
        $preferences = $this->readPreferencesArray($userId);

        return $preferences[$key] ?? null;
    }

    public function writeUserPreference(int $userId, string $key, object|string|bool|null $value): void
    {
        $preferences = $this->readPreferencesArray($userId);
        $preferences[$key] = $value;

        $this->updatePreferencesStatement->bindValue(':user_id', $userId);
        $this->updatePreferencesStatement->bindValue(':preferences', json_encode($preferences, JSON_THROW_ON_ERROR & JSON_UNESCAPED_UNICODE));
        $this->updatePreferencesStatement->execute();
    }

    public function readPreferencesArray(int $userId): ?array
    {
        $this->readPreferencesStatement->bindValue(':user_id', $userId);
        $result = $this->readPreferencesStatement->execute();
        $preferencesArray = $result->fetchArray(SQLITE3_ASSOC);
        if ($preferencesArray === false) {
            return [];
        }

        return json_decode($preferencesArray['preferences'], true, flags: JSON_THROW_ON_ERROR);

    }
}
