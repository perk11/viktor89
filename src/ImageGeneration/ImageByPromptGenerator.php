<?php

namespace Perk11\Viktor89\ImageGeneration;

interface ImageByPromptGenerator
{
    public function generateImageByImagePrompt(ImageGenerationPrompt $imageGenerationPrompt, int $userId): Automatic1111ImageApiResponse;
    public function generateImageByPrompt(string $prompt, int $userId): Automatic1111ImageApiResponse;
    
}
