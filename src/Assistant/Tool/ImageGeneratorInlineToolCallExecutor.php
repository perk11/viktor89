<?php

namespace Perk11\Viktor89\Assistant\Tool;

use Perk11\Viktor89\ImageGeneration\ImageByPromptGenerator;
use Perk11\Viktor89\ImageGeneration\ImageGenerationPrompt;
use Perk11\Viktor89\ImageGeneration\ImgTagExtractor;
use Perk11\Viktor89\MessageChain;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class ImageGeneratorInlineToolCallExecutor implements MessageChainAwareToolCallExecutorInterface
{
    public function __construct(
        private readonly ImageByPromptGenerator $imageByPromptGenerator,
        private readonly ?ImageByPromptGenerator $editByPromptGenerator,
        private readonly ImageUploader $generatedImageMarkdownUploader,
        private readonly ImgTagExtractor $imgTagExtractor,
        private readonly LoggerInterface $logger,
    )
    {
    }

    public function executeToolCall(array $arguments, MessageChain $messageChain): array
    {
        if (!isset($arguments['prompt'])) {
            throw new \InvalidArgumentException('Prompt is required');
        }
        if (!is_string($arguments['prompt'])) {
            throw new \InvalidArgumentException('Prompt must be a string');
        }
        foreach ($arguments as $key => $value) {
            if ($key !== 'prompt') {
                throw new \InvalidArgumentException("Unsupported argument: $key");
            }
        }

        $lastMessage = $messageChain->last();
        $hasImageReferences = str_contains($arguments['prompt'], '<img>') && str_contains($arguments['prompt'], '</img>');
        $generator = ($hasImageReferences && $this->editByPromptGenerator !== null)
            ? $this->editByPromptGenerator
            : $this->imageByPromptGenerator;

        $prompt = $this->imgTagExtractor->extractImageTags(
            new ImageGenerationPrompt($arguments['prompt']),
            null,
            $messageChain,
        );
        try {
            $response = $generator->generateImageByImagePrompt($prompt, $lastMessage->userId);
            $uploadedImage = $this->generatedImageMarkdownUploader->uploadPng($response->getFirstImageAsPng());
        } catch (\Exception $e) {
            $this->logger->log(LogLevel::ERROR, $e->getMessage() . "\n" . $e->getTraceAsString());
            return [
                'status' => 'failed',
                'content' => 'Image generation failed.',
            ];
        }

        return [
            'status' => 'image_succesfully_generated_and_sent_to_user',
            'directions' => 'Do not attempt to send the image to the user or embed it again. The user has the generated image but you cannot embed it again.',
            'automatic_output_markdown' => $uploadedImage->toRichMarkdown($response->getCaption()),
        ];
    }
}
