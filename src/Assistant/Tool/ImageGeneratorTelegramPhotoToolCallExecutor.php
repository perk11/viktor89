<?php

namespace Perk11\Viktor89\Assistant\Tool;

use Perk11\Viktor89\ImageGeneration\Automatic1111APiClient;
use Perk11\Viktor89\ImageGeneration\ImageByPromptGenerator;
use Perk11\Viktor89\ImageGeneration\ImageGenerationPrompt;
use Perk11\Viktor89\ImageGeneration\ImgTagExtractor;
use Perk11\Viktor89\ImageGeneration\PhotoResponder;
use Perk11\Viktor89\MessageChain;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class ImageGeneratorTelegramPhotoToolCallExecutor implements MessageChainAwareToolCallExecutorInterface
{
    public function __construct(
        private readonly ImageByPromptGenerator $imageByPromptGenerator,
        private readonly ?ImageByPromptGenerator $editByPromptGenerator,
        private readonly PhotoResponder $photoResponder,
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
            $image = $response->getFirstImageAsPng();
            $this->photoResponder->sendPhoto($lastMessage, $image, $response->sendAsFile, $response->getCaption());
        } catch (\Exception $e) {
            $this->logger->log(LogLevel::ERROR, $e->getMessage() . "\n" . $e->getTraceAsString());
            return ['status' => 'failed'];
        }

        return [
            'status' => 'image_succesfully_generated_and_sent_to_user',
            'context_image' => $image,
        ];
    }
}

