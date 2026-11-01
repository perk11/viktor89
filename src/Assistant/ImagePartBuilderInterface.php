<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant;

interface ImagePartBuilderInterface
{
    /**
     * Wraps raw image bytes in the API's image content part.
     *
     * @return array<string, mixed>|null null when the image cannot be sent
     */
    public function buildImagePart(string $image): ?array;
}
