<?php

namespace Perk11\Viktor89\Repository;

use Perk11\Viktor89\Database;

class PatchRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function findMissingPatches(array $links): array
    {

        $placeholders = [];
        foreach ($links as $index => $link) {
            $placeholders[] = ':link' . $index;
        }

        $sql = 'SELECT link FROM patches WHERE link IN (' . implode(',', $placeholders) . ')';
        $statement = $this->database->sqlite3Database->prepare($sql);

        foreach ($links as $index => $link) {
            $statement->bindValue(':link' . $index, $link, SQLITE3_TEXT);
        }

        $executionResult = $statement->execute();
        $existingLinks = [];

        while ($link = $executionResult->fetchArray(SQLITE3_NUM)) {
            $existingLinks[] = $link[0];
        }

        $missingLinks = [];
        foreach ($links as $link) {
            if (!in_array($link, $existingLinks, true)) {
                $missingLinks[] = $link;
            }
        }

        return $missingLinks;
    }

    public function insertPatch(string $link): void
    {
        $statement = $this->database->sqlite3Database->prepare(' INSERT INTO patches (link) VALUES (:link)');
        $statement->bindValue(':link', $link);
        $statement->execute();
    }
}
