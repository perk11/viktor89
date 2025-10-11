<?php

namespace Perk11\Viktor89\ImageGeneration;

use Perk11\Viktor89\PreResponseProcessor\SavedImageNotFoundException;

class ImgTagExtractor
{
    public function __construct(private readonly ImageRepository $imageRepository)
    {
    }

    private const string IMG_REGEX = '/<img>(.*?)<\/img>/s';

    public function extractImageTags(
        ImageGenerationPrompt $promptTobeProcessed,
        string $modelName
    ): ImageGenerationPrompt {
        $newPrompt = clone $promptTobeProcessed;
        $newPrompt->text = preg_replace_callback(
            self::IMG_REGEX,
            function ($matches) use (&$newPrompt, $modelName) {
                $savedImage = $this->imageRepository->retrieve($matches[1]);
                if ($savedImage === null) {
                    throw new SavedImageNotFoundException($matches[1]);
                }
                $newPrompt->sourceImagesContents[] = $savedImage;
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
}
