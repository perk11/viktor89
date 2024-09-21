<?php

namespace Perk11\Viktor89\ImageGeneration;

interface ImageByPromptGenerator
{
    public function generateImageByPrompt(string $prompt, int $userId): Automatic1111ImageApiResponse;
    
}
