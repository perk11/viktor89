<?php

namespace Perk11\Viktor89;

use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\AssistantContextMessage;
use Perk11\Viktor89\Assistant\ContextCompletingAssistantInterface;
use Perk11\Viktor89\ImageGeneration\Automatic1111APiClient;
use Perk11\Viktor89\ImageGeneration\Automatic1111ImageApiResponse;
use Perk11\Viktor89\ImageGeneration\ImageByPromptAndImageGenerator;
use Perk11\Viktor89\ImageGeneration\ImageByPromptGenerator;
use Perk11\Viktor89\ImageGeneration\ImageGenerationPrompt;

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
        echo "Improved prompt from assistant: $improvedPrompt\n";

        return $this->automatic1111APiClient->generateImageByPrompt($improvedPrompt, $userId);
    }

    private function processPrompt(string $originalPrompt, int $userId): string
    {
        $modelName = $this->imageModelPreference->getCurrentPreferenceValue($userId);
        $params = $this->modelConfig[$modelName];
        $context = new AssistantContext();
        $context->systemPrompt = $params['assistantPrompt'] ??
            "Given a message, add details, reword and expand on it in a way that describes an image illustrating user's message.  This text will be used to generate an image using automatic text to image generator that does not understand emotions, metaphors, negatives, abstract concepts. Important parts of the image should be specifically described, leaving no room for interpretation. Your output should contain only a literal description of the image in a single sentence. Only describe what an observer will see. Your output will be directly passed to an API, so don't output anything extra. Do not use any syntax or code formatting, just output raw text describing the image and nothing else. Translate the output to English. Your message to describe follows bellow:";
        $userMessage = new AssistantContextMessage();
        $userMessage->isUser = true;
        $userMessage->text = $originalPrompt;
        $context->messages[] = $userMessage;

        return $this->assistant->getCompletionBasedOnContext($context);
    }

    public function generateImageByPromptAndImages(
        ImageGenerationPrompt $imageGenerationPrompt,
        int $userId
    ): Automatic1111ImageApiResponse {
        $improvedPrompt = clone $imageGenerationPrompt;
        $context = new AssistantContext();
        $context->systemPrompt = "Given a message and an image, return a prompt for an AI image editor that will implement the changes requested in the message. Be concrete about the changes that need to be made, as the editor does not understand emotions, metaphors, negatives, abstract concepts. Your output will be directly passed to an API, so don't output anything extra. Do not use any syntax or code formatting, just output raw text describing the changes that need to be maade and nothing else. Translate the output to English.";
        $userMessage = new AssistantContextMessage();
        $userMessage->isUser = true;
        $userMessage->photo = current($imageGenerationPrompt->sourceImagesContents);
        $userMessage->text = $imageGenerationPrompt->text;
        $context->messages[] = $userMessage;
        $improvedPrompt->text = $this->assistant->getCompletionBasedOnContext($context);

        echo "Edit prompt from assistant: " . $improvedPrompt->text . "\n";

        return $this->automatic1111APiClient->generateImageByPromptAndImages(
            $improvedPrompt,
            $userId,
        );
    }
}
