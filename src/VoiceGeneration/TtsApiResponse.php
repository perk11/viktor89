<?php

namespace Perk11\Viktor89\VoiceGeneration;

use RuntimeException;

class TtsApiResponse
{
    public function __construct(public string $voiceFileContents, public array $info)
    {
    }

    public static function fromString(string $getContents): self
    {
        $decoded = json_decode($getContents, true, 512, JSON_THROW_ON_ERROR);
        if (!array_key_exists('voice_data', $decoded) || !is_string($decoded['voice_data'])
            || !array_key_exists('info', $decoded) || !is_array($decoded['info'])
        ) {
            throw new RuntimeException("Unexpected response from Voice API:\n" . $getContents);
        }

        return new self(
            base64_decode($decoded['voice_data']),
            $decoded['info'],
        );
    }
}
