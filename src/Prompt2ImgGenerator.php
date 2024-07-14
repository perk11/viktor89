<?php

namespace Perk11\Viktor89;

interface Prompt2ImgGenerator
{
    public function generateByPromptTxt2Img(string $prompt, int $userId): Automatic1111ImageApiResponse;
    
}
