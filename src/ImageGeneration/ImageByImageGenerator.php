<?php

namespace Perk11\Viktor89\ImageGeneration;

interface ImageByImageGenerator
{
    public function processImage(string $imageContent, int $userId): Automatic1111ImageApiResponse;
}
