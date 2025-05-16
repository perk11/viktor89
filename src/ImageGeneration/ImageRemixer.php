<?php

namespace Perk11\Viktor89\ImageGeneration;

use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\AssistantContextMessage;
use Perk11\Viktor89\Assistant\ContextCompletingAssistantInterface;

class ImageRemixer
{
    public function __construct(
        private readonly ContextCompletingAssistantInterface $assistantWithVision,
        private readonly Automatic1111APiClient $automatic1111APiClient,
    )
    {

    }

    public function remixImage(string $image, int $userId): Automatic1111ImageApiResponse
    {
        $assistantContext = new AssistantContext();
        $assistantContext->systemPrompt = 'Describe the image sent by the user. Your responses will be fed directly to an image generator. Be as detailed as possible so that everything can be re-captured in the new image, but do not add anything that is not related to the image.';
        $message = new AssistantContextMessage();
        $message->isUser = true;
        $message->photo = $image;
        $message->text = 'Describe this image. Your responses will be fed directly to an image generator. Be as detailed as possible so that everything can be re-captured in the new image, but do not add anything that is not related to the image. Open with image description, your first word should already describe the image. Do not break description into sections';
        $assistantContext->messages[] = $message;

        $prompt = $this->assistantWithVision->getCompletionBasedOnContext($assistantContext);
        echo "Remix prompt: $prompt\n";

        return $this->automatic1111APiClient->generateImageByPrompt($prompt, $userId);
    }
}
