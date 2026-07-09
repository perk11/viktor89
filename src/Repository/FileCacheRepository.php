<?php

namespace Perk11\Viktor89\Repository;

use Perk11\Viktor89\Database;

class FileCacheRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function readFileCacheNameById(string $fileId): ?string
    {
        $statement = $this->database->sqlite3Database->prepare('SELECT file_name FROM file_cache WHERE file_id = :file_id');
        $statement->bindValue(':file_id', $fileId);
        $result = $statement->execute();

        $resultArray = $result->fetchArray(SQLITE3_NUM);
        if ($resultArray === false) {
            return null;
        }
        return $resultArray[0];
    }

    public function readFileCacheNameBySha1(string $sha1): ?string
    {
        $statement = $this->database->sqlite3Database->prepare('SELECT file_name FROM file_cache WHERE sha1 = :sha1');
        $statement->bindValue(':sha1', $sha1);
        $result = $statement->execute();

        $resultArray = $result->fetchArray(SQLITE3_NUM);
        if ($resultArray === false) {
            return null;
        }
        return $resultArray[0];
    }

    public function writeFileCache(string $fileId, string $fileName, string $sha1): void
    {
        $statement = $this->database->sqlite3Database->prepare('INSERT INTO file_cache (file_id, file_name, sha1) VALUES (:file_id, :file_name, :sha1)');
        $statement->bindValue(':file_id', $fileId);
        $statement->bindValue(':file_name', $fileName);
        $statement->bindValue(':sha1', $sha1);
        $statement->execute();
    }
}
