<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool\ImageSearch;

interface ImageSearchProviderInterface
{
    /**
     * Image URLs that may match the query, most promising first. They are not
     * guaranteed to be downloadable or relevant.
     *
     * @return list<ImageSearchCandidate>
     */
    public function findCandidates(string $query, int $limit): array;
}
