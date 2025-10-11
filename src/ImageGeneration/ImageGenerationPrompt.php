<?php

namespace Perk11\Viktor89\ImageGeneration;

class ImageGenerationPrompt
{
    public function __construct(public string $text, public array $sourceImagesContents = [])
    {
    }
}
