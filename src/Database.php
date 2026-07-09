<?php

namespace Perk11\Viktor89;

use RuntimeException;
use SQLite3;

/**
 * Owns the SQLite connection and schema. Entity-specific persistence lives in
 * the Perk11\Viktor89\Repository\* classes, which receive this instance to
 * access the shared {@see $sqlite3Database} connection and {@see $botUserId}.
 */
class Database
{
    public readonly SQLite3 $sqlite3Database;

    public function __construct(public readonly int $botUserId, string $name)
    {
        $databaseDir = dirname(__DIR__) . '/data';
        if (!@mkdir($databaseDir) && !is_dir($databaseDir)) {
            throw new RuntimeException(sprintf('Failed to create directory "%s"', $databaseDir));
        }
        $this->sqlite3Database = new SQLite3($databaseDir . "/" . $name);
        $this->sqlite3Database->busyTimeout(30000);
        $this->sqlite3Database->query(file_get_contents(__DIR__ . '/db-structure.sql'));
    }
}
