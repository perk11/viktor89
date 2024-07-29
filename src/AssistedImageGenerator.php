<?php

namespace Perk11\Viktor89;

use Orhanerday\OpenAi\OpenAi;
use Perk11\Viktor89\Assistant\AbstractOpenAIAPICompletionAssistant;
use Perk11\Viktor89\ImageGeneration\Automatic1111APiClient;
use Perk11\Viktor89\ImageGeneration\Automatic1111ImageApiResponse;
use Perk11\Viktor89\ImageGeneration\Prompt2ImgGenerator;
use Perk11\Viktor89\ImageGeneration\PromptAndImg2ImgGenerator;

class AssistedImageGenerator implements Prompt2ImgGenerator, PromptAndImg2ImgGenerator
{

    public function __construct(
        private readonly Automatic1111APiClient $automatic1111APiClient,
        private readonly AbstractOpenAIAPICompletionAssistant $assistant,
    ) {
    }

    public function generateByPromptTxt2Img(string $prompt, int $userId): Automatic1111ImageApiResponse
    {
        $improvedPrompt = $this->processPrompt($prompt);
        return $this->automatic1111APiClient->generateByPromptTxt2Img($improvedPrompt, $userId);
    }

    public function generatePromptAndImageImg2Img(
        string $imageContent,
        string $prompt,
        int $userId
    ): Automatic1111ImageApiResponse {
        $improvedPrompt = $this->processPrompt($prompt);
        return $this->automatic1111APiClient->generatePromptAndImageImg2Img($imageContent, $improvedPrompt, $userId);
    }

    private function processPrompt(string $originalPrompt): string
    {
        $systemPrompt = "Given a message, add details, reword and expand on it in a way that describes an image illustrating user's message.  This text will be used to generate an image using automatic text to image generator that does not understand emotions, metaphors or negatives. Your output should contain only a literal description of the image in a single sentence. Only describe what an observer will see. Your output will be directly passed to an API, so don't output anything extra. Do not use any syntax or code formatting, just output raw text describing the image and nothing else. Translate the output to English. Your message to describe follows bellow:";

        return $this->assistant->getCompletionBasedOnSingleStringQuestion($originalPrompt, $systemPrompt);
    }
}
