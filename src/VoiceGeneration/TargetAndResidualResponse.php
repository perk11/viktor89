<?php

namespace Perk11\Viktor89\VoiceGeneration;

use RuntimeException;

readonly class TargetAndResidualResponse
{
    public function __construct(
        public string $target,
        public string $residual,
    ) {
    }

    public static function fromString(string $getContents): self
    {
        $decoded = json_decode($getContents, true, 512, JSON_THROW_ON_ERROR);
        if (!array_key_exists('target', $decoded) || !is_string($decoded['target'])
            || !array_key_exists('residual', $decoded) || !is_string($decoded['residual'])
        ) {
            throw new RuntimeException("Unexpected response TargetAndResidual API:\n" . $getContents);
        }

        return new self(
            base64_decode($decoded['target']),
            base64_decode($decoded['residual']),
        );
    }
}
