<?php

namespace Perk11\Viktor89\ImageGeneration;

use Perk11\Viktor89\Assistant\AssistantContext;
use Perk11\Viktor89\Assistant\AssistantContextMessage;
use Perk11\Viktor89\Assistant\ContextCompletingAssistantInterface;
use Perk11\Viktor89\UserPreferenceReaderInterface;

class RestyleGenerator implements ImageByImageGenerator
{
    public function __construct(
        private readonly ImageByPromptAndImageGenerator $client,
        private readonly UserPreferenceReaderInterface $stylePreference,
        private readonly ImageRepository $imageRepository,
        private readonly ContextCompletingAssistantInterface $assistantWithVision,
    )
    {
    }

    public function processImage(string $imageContent, int $userId, ?string $prompt = ''): Automatic1111ImageApiResponse
    {

        $style = $this->stylePreference->getCurrentPreferenceValue($userId);
        if ($style === null) {
            $style = 'default_style';
        }
        if ($prompt === '') {
            echo "User sent a blank prompt, guessing the prompt from the image...";
            $assistantContext = new AssistantContext();
            $assistantContext->systemPrompt = 'Describe the image sent by the user so that it can be drawn in a different style. If there is a subject, only describe the subject. Your responses will be fed directly to an image generator, so do not output any formatting, just the description.';
            if ($style !== 'default_style') {
                $assistantContext->systemPrompt .= 'Style name might or might not be related. Use the style name for reference only if you can identify what it means.';
            }
            $message = new AssistantContextMessage();
            $message->isUser = true;
            $message->photo = $imageContent;
            $message->text = 'Describe this image.';
            if ($style !== 'default_style') {
                $message->text .= "Target style name is \"$style\"";
            }
            $assistantContext->messages[] = $message;

            $prompt = $this->assistantWithVision->getCompletionBasedOnContext($assistantContext);
            echo "Generated restyle prompt: $prompt\n";
        }
        $styleImageData = $this->imageRepository->retrieve($style);
        if ($styleImageData === null) {
            throw new ImageGeneratorBadRequestException("Не найдено образца стиля с именем \"$style\", сохраните его используя команду /saveas");
        }

        $response = $this->client->generateImageByPromptAndImages([$imageContent, $styleImageData], $prompt, $userId);
        $response->info['infotexts'][0] .=", Style: $style";

        return $response;
    }
}
