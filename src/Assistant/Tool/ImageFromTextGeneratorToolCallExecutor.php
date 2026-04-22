<?php

namespace Perk11\Viktor89\Assistant\Tool;

use Perk11\Viktor89\ImageGeneration\Automatic1111APiClient;
use Perk11\Viktor89\ImageGeneration\ImageByPromptGenerator;
use Perk11\Viktor89\ImageGeneration\PhotoResponder;
use Perk11\Viktor89\MessageChain;

class ImageFromTextGeneratorToolCallExecutor implements MessageChainAwareToolCallExecutorInterface
{
    public function __construct(
        private readonly ImageByPromptGenerator $imageByPromptGenerator,
        private readonly PhotoResponder $photoResponder,
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

        $lastMesage = $messageChain->last();
        try {
            $response = $this->imageByPromptGenerator->generateImageByPrompt($arguments['prompt'], $lastMesage->userId);
            $this->photoResponder->sendPhoto($lastMesage, $response->getFirstImageAsPng(), $response->sendAsFile, $response->getCaption());
        } catch (\Exception $e) {
            echo $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            return ['status' => 'failed'];
        }

        return ['status' => 'image_succesfully_generated_and_sent_to_user'];
    }
}
