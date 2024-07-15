<?php

namespace Perk11\Viktor89\ImageGeneration;

interface Prompt2ImgGenerator
{
    public function generateByPromptTxt2Img(string $prompt, int $userId): Automatic1111ImageApiResponse;
    
}
