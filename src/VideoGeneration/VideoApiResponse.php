<?php

namespace Perk11\Viktor89\VideoGeneration;

use RuntimeException;

class VideoApiResponse
{
    /**
     * The name of the model that produced this video, filled in by the client
     * from the user's selection (falling back to the first configured model).
     * Used to label the video caption and to record generation metadata.
     */
    public ?string $modelName = null;

    public function __construct(public array $videos, public array $info)
    {
    }

    public static function fromString(string $data): self
    {
        $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        if (!array_key_exists('videos', $decoded) || !is_array($decoded['videos'])
            || !array_key_exists('info', $decoded) || !is_string($decoded['info'])
        ) {
            throw new RuntimeException("Unexpected response from Video API:\n" . $$data);
        }

        return new self(
            $decoded['videos'],
            json_decode($decoded['info'], true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function getFirstVideoAsMp4(): string
    {
        return base64_decode($this->videos[0]);
    }

    public function getCaption(): ?string
    {
        return $this->info['infotexts'][0] ?? null;
    }
}
