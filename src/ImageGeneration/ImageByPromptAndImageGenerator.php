<?php

namespace Perk11\Viktor89\ImageGeneration;

interface ImageByPromptAndImageGenerator
{
    public function generateImageByPromptAndImages(
        ImageGenerationPrompt $imageGenerationPrompt,
        int $userId
    ): Automatic1111ImageApiResponse;
}
