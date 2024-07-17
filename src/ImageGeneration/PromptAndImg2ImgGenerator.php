<?php

namespace Perk11\Viktor89\ImageGeneration;

interface PromptAndImg2ImgGenerator
{
    public function generatePromptAndImageImg2Img(
        string $imageContent,
        string $prompt,
        int $userId
    ): Automatic1111ImageApiResponse;
}
