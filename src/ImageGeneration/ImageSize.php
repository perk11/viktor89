<?php

namespace Perk11\Viktor89\ImageGeneration;

class ImageSize
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
    ) {
    }
}
