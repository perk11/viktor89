<?php

namespace Perk11\Viktor89\ImageGeneration;

interface ImageByPromptAndImageGenerator
{
    public function generateImageByPromptAndImages(
        array $imageContents,
        string $prompt,
        int $userId
    ): Automatic1111ImageApiResponse;
}
