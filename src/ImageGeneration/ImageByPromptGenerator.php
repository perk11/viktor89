<?php

namespace Perk11\Viktor89\ImageGeneration;

use Perk11\Viktor89\IPC\ProgressUpdateCallback;

interface ImageByPromptGenerator
{
    public function generateImageByImagePrompt(
        ImageGenerationPrompt $imageGenerationPrompt,
        int $userId,
        ?ProgressUpdateCallback $progressUpdateCallback = null,
    ): Automatic1111ImageApiResponse;
    public function generateImageByPrompt(string $prompt, int $userId): Automatic1111ImageApiResponse;

}
