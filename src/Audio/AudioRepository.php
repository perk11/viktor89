<?php

namespace Perk11\Viktor89\Audio;

use RangeException;
use RuntimeException;
use SQLite3;

class AudioRepository
{
    public function __construct(private readonly SQLite3 $sqlite3Database)
    {
    }

    private const FILE_STORAGE_DIR = __DIR__ . '/../../data/audios';

    /** returns file contents of the audio or null  */
    public function retrieve(string $name): ?string
    {
        $selectStatement = $this->sqlite3Database->prepare('SELECT filename FROM saved_audio WHERE name = :name');
        $selectStatement->bindValue(':name', $name, SQLITE3_TEXT);
        $result = $selectStatement->execute();

        $row = $result->fetchArray(SQLITE3_ASSOC);

        if ($row === false) {
            return null;
        }

        $filePath = self::FILE_STORAGE_DIR . DIRECTORY_SEPARATOR . $row['filename'];

        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: " . $filePath);
        }

        return file_get_contents($filePath) ?: null;
    }

    /* returns false if the name is already in use  */
    public function save(string $name, int $userId, string $fileContents, string $extension = 'ogg'): bool
    {
        $existingFile = $this->retrieve($name);
        if ($existingFile !== null) {
            return false;
        }

        if (!is_dir(self::FILE_STORAGE_DIR) && !mkdir(
                $concurrentDirectory = self::FILE_STORAGE_DIR,
                0777,
                true
            ) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
        }

        $extension = ltrim($extension, '.');
        $fileName = $this->random_str() . '.' . $extension;
        $filePath = self::FILE_STORAGE_DIR . DIRECTORY_SEPARATOR . $fileName;

        while (file_exists($filePath)) {
            $fileName = $this->random_str() . '.' . $extension;
            $filePath = self::FILE_STORAGE_DIR . DIRECTORY_SEPARATOR . $fileName;
        }
        $bytesWritten = file_put_contents($filePath, $fileContents);

        // If writing to the file failed, consider handling it (e.g., throw an exception or return false).
        if ($bytesWritten === false) {
            throw new RuntimeException(sprintf('Unable to write file "%s": %s', $filePath, error_get_last()));
        }
        $stmtInsert = $this->sqlite3Database->prepare(
            'INSERT INTO saved_audio (name, filename, user_id, created_at) VALUES (:name, :filename, :user_id, CURRENT_TIMESTAMP)'
        );
        $stmtInsert->bindValue(':name', $name);
        $stmtInsert->bindValue(':filename', $fileName);
        $stmtInsert->bindValue(':user_id', $userId, SQLITE3_INTEGER);

        $insertResult = $stmtInsert->execute();
        if ($insertResult === false) {
            throw new RuntimeException("Failed to save audio: " . $this->sqlite3Database->lastErrorMsg());
        }

        return true;
    }

    /**
     * @return SavedAudio[]
     */
    public function findAllPublicAudios(): array
    {
        $stmt = $this->sqlite3Database->prepare(
            'SELECT id, name, filename, user_id, created_at, private FROM saved_audio WHERE private = 0 ORDER BY name'
        );
        $result = $stmt->execute();

        $audios = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $audios[] = new SavedAudio(
                $row['id'],
                $row['name'],
                $row['filename'],
                $row['user_id'],
                $row['created_at'],
                (bool)$row['private'],
            );
        }
        return $audios;
    }

    private function random_str(
        int $length = 64,
        string $keyspace = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'
    ): string {
        if ($length < 1) {
            throw new RangeException("Length must be a positive integer");
        }
        $pieces = [];
        $max = mb_strlen($keyspace, '8bit') - 1;
        for ($i = 0; $i < $length; ++$i) {
            $pieces [] = $keyspace[random_int(0, $max)];
        }

        return implode('', $pieces);
    }
}
