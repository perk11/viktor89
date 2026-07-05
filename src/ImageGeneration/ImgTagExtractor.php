<?php

namespace Perk11\Viktor89\ImageGeneration;

use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\PreResponseProcessor\SavedImageNotFoundException;
use Perk11\Viktor89\TelegramFileDownloader;

class ImgTagExtractor
{
    public function __construct(
        private readonly ImageRepository $imageRepository,
        private readonly ?TelegramFileDownloader $telegramFileDownloader = null,
    ) {
    }

    private const string IMG_REGEX = '/<img>(.*?)<\/img>/s';

    public function extractImageTags(
        ImageGenerationPrompt $promptTobeProcessed,
        ?string $modelName = null,
        ?MessageChain $messageChain = null,
    ): ImageGenerationPrompt {
        $newPrompt = clone $promptTobeProcessed;
        $newPrompt->text = preg_replace_callback(
            self::IMG_REGEX,
            function ($matches) use (&$newPrompt, $modelName, $messageChain) {
                $reference = trim($matches[1]);

                // Check if this is a chain image reference (e.g., "#1", "#2")
                if (str_starts_with($reference, '#') && $messageChain !== null) {
                    $imageIndex = (int)substr($reference, 1);
                    $imageData = $this->resolveChainImage($messageChain, $imageIndex);
                    if ($imageData === null) {
                        throw new SavedImageNotFoundException("Chain image $reference not found");
                    }
                    $newPrompt->sourceImagesContents[] = $imageData;
                } else {
                    // Standard saved image reference
                    $savedImage = $this->imageRepository->retrieve($reference);
                    if ($savedImage === null) {
                        throw new SavedImageNotFoundException($reference);
                    }
                    $newPrompt->sourceImagesContents[] = $savedImage;
                }

                if ($modelName === 'OmniGen-v1') {
                    return '<img><|image_' . (count($newPrompt->sourceImagesContents)) . '|></img>';
                }

                if ($modelName === 'OmniGen-v2') {
                    return "image " . count($newPrompt->sourceImagesContents);
                }

                return '';
            },
            $promptTobeProcessed->text,
        );

        if ($promptTobeProcessed->text !== $newPrompt->text) {
            echo "Prompt changed to $newPrompt->text\n";
        }
        return $newPrompt;
    }

    /**
     * Resolve a chain image reference to its binary contents.
     * $imageIndex is 0-based.
     */
    private function resolveChainImage(MessageChain $messageChain, int $imageIndex): ?string
    {
        if ($imageIndex < 0) {
            return null;
        }

        $foundIndex = 0;
        foreach ($messageChain->getMessages() as $message) {
            if ($message->photoFileId !== null) {
                if ($foundIndex === $imageIndex) {
                    try {
                        return $this->telegramFileDownloader->downloadPhotoFromInternalMessage($message);
                    } catch (\Exception $e) {
                        echo "Failed to download chain image $imageIndex: " . $e->getMessage() . "\n";
                        return null;
                    }
                }
                $foundIndex++;
            }
        }

        return null;
    }
}
