<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool\ImageSearch;

final readonly class ImageSearchCandidate
{
    public function __construct(
        public ?string $title,
        public string $imageUrl,
        public string $sourcePage,
    ) {
    }
}
