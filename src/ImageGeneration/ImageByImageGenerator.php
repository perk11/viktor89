<?php

namespace Perk11\Viktor89\ImageGeneration;

interface ImageByImageGenerator
{
    public function processImage(string $imageContent, int $userId, ?string $prompt = ''): Automatic1111ImageApiResponse;
}
