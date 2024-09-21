<?php

namespace Perk11\Viktor89\ImageGeneration;

interface ImageByPromptAndImageGenerator
{
    public function generateImageByPromptAndImage(
        string $imageContent,
        string $prompt,
        int $userId
    ): Automatic1111ImageApiResponse;
}
