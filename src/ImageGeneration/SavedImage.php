<?php

namespace Perk11\Viktor89\ImageGeneration;

class SavedImage
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $filename,
        public readonly int $userId,
        public readonly string $createdAt,
        public readonly bool $private,
    ) {
    }
}
