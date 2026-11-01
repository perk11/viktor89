<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Repository;

use Perk11\Viktor89\Assistant\Tool\ImageSearch\ImageSearchCandidate;
use Perk11\Viktor89\Database;

class SearchedImageRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function add(string $query, ImageSearchCandidate $candidate): void
    {
        $statement = $this->database->sqlite3Database->prepare(
            'INSERT OR REPLACE INTO searched_image (query, image_url, title, source_page, created_at)
             VALUES (:query, :image_url, :title, :source_page, :created_at)'
        );
        $statement->bindValue(':query', self::normalizeQuery($query));
        $statement->bindValue(':image_url', $candidate->imageUrl);
        $statement->bindValue(':title', $candidate->title);
        $statement->bindValue(':source_page', $candidate->sourcePage);
        $statement->bindValue(':created_at', time(), SQLITE3_INTEGER);
        $statement->execute();
    }

    /** @return list<ImageSearchCandidate> most recently picked first */
    public function findByQuery(string $query, int $limit): array
    {
        $statement = $this->database->sqlite3Database->prepare(
            'SELECT * FROM searched_image WHERE query = :query ORDER BY created_at DESC, rowid DESC LIMIT :limit'
        );
        $statement->bindValue(':query', self::normalizeQuery($query));
        $statement->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $statement->execute();

        $candidates = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $candidates[] = new ImageSearchCandidate($row['title'], $row['image_url'], $row['source_page']);
        }

        return $candidates;
    }

    /** SQLite's NOCASE only folds ASCII, so non-Latin queries are lowercased here. */
    private static function normalizeQuery(string $query): string
    {
        return mb_strtolower($query);
    }
}
