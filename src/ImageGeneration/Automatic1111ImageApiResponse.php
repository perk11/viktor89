<?php

namespace Perk11\Viktor89\ImageGeneration;

class Automatic1111ImageApiResponse
{
    public function __construct(public array $images, public array $parameters, public array $info)
    {
    }

    public static function fromString(string $data): self
    {
        $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        if (!array_key_exists('images', $decoded) || !is_array($decoded['images'])
            || !array_key_exists('parameters', $decoded) || !is_array($decoded['parameters'])
            || !array_key_exists('info', $decoded) || !is_string($decoded['info'])
        ) {
            throw new \RuntimeException("Unexpected response from Automatic1111 API:\n" . $$data);
        }

        return new self(
            $decoded['images'],
            $decoded['parameters'],
            json_decode($decoded['info'], true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function getFirstImageAsPng(): string
    {
        return base64_decode($this->images[0]);
    }

    public function getCaption(): ?string
    {
        return $this->info['infotexts'][0] ?? null;
    }
}
