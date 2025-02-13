<?php

namespace Perk11\Viktor89;

use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\AssistantContextMessage;
use Perk11\Viktor89\Assistant\ContextCompletingAssistantInterface;
use Perk11\Viktor89\ImageGeneration\Automatic1111APiClient;
use Perk11\Viktor89\ImageGeneration\Automatic1111ImageApiResponse;
use Perk11\Viktor89\ImageGeneration\ImageByPromptAndImageGenerator;
use Perk11\Viktor89\ImageGeneration\ImageByPromptGenerator;

class AssistedImageGenerator implements ImageByPromptGenerator, ImageByPromptAndImageGenerator
{

    public function __construct(
        private readonly Automatic1111APiClient $automatic1111APiClient,
        private readonly ContextCompletingAssistantInterface $assistant,
        private readonly UserPreferenceReaderInterface $imageModelPreference,
        private readonly array $modelConfig,
    ) {
    }

    public function generateImageByPrompt(string $prompt, int $userId): Automatic1111ImageApiResponse
    {
        $improvedPrompt = $this->processPrompt($prompt, $userId);
        return $this->automatic1111APiClient->generateImageByPrompt($improvedPrompt, $userId);
    }

    public function generateImageByPromptAndImages(
        array $imageContents,
        string $prompt,
        int $userId
    ): Automatic1111ImageApiResponse {
        $improvedPrompt = $this->processPrompt($prompt, $userId);

        return $this->automatic1111APiClient->generateImageByPromptAndImages(
            $imageContents,
            $improvedPrompt,
            $userId
        );
    }

    private function processPrompt(string $originalPrompt, int $userId): string
    {
        $modelName = $this->imageModelPreference->getCurrentPreferenceValue($userId);
        if ($modelName === null || !array_key_exists($modelName, $this->modelConfig)) {
            $params = current($this->modelConfig);
        } else {
            $params = $this->modelConfig[$modelName];
        }
        $context = new AssistantContext();
        $context->systemPrompt = $params['assistantPrompt'] ??
            "Given a message, add details, reword and expand on it in a way that describes an image illustrating user's message.  This text will be used to generate an image using automatic text to image generator that does not understand emotions, metaphors, negatives, abstract concepts. Important parts of the image should be specifically described, leaving no room for interpretation. Your output should contain only a literal description of the image in a single sentence. Only describe what an observer will see. Your output will be directly passed to an API, so don't output anything extra. Do not use any syntax or code formatting, just output raw text describing the image and nothing else. Translate the output to English. Your message to describe follows bellow:";
        $userMessage = new AssistantContextMessage();
        $userMessage->isUser = true;
        $userMessage->text = $originalPrompt;
        $context->messages[] = $userMessage;

        return $this->assistant->getCompletionBasedOnContext($context);
    }
}
