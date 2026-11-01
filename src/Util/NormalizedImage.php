<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Util;

final readonly class NormalizedImage
{
    public function __construct(
        public string $bytes,
        public string $mediaType,
    ) {
    }

    public function toBase64(): string
    {
        return base64_encode($this->bytes);
    }

    public function toDataUrl(): string
    {
        return "data:$this->mediaType;base64," . $this->toBase64();
    }
}
