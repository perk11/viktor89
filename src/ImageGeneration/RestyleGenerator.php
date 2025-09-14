<?php

namespace Perk11\Viktor89\ImageGeneration;

use Perk11\Viktor89\UserPreferenceReaderInterface;

class RestyleGenerator implements ImageByImageGenerator
{
    public function __construct(
        private readonly ImageByPromptAndImageGenerator $client,
        private readonly UserPreferenceReaderInterface $stylePreference,
        private readonly ImageRepository $imageRepository,
    )
    {
    }

    public function processImage(string $imageContent, int $userId, ?string $prompt = ''): Automatic1111ImageApiResponse
    {
        if ($prompt === '') {
            throw new ImageGeneratorBadRequestException("Добавьте описание нового изображения на английском после команды, например /restyle Man wearing red sunglasses standing in front of the river");
        }
        $style = $this->stylePreference->getCurrentPreferenceValue($userId);
        if ($style === null) {
            $style = 'default_style';
        }
        $styleImageData = $this->imageRepository->retrieve($style);
        if ($styleImageData === null) {
            throw new ImageGeneratorBadRequestException("Не найдено образца стиля с именем \"$style\", сохраните его используя команду /saveas");
        }

        return $this->client->generateImageByPromptAndImages([$imageContent, $styleImageData], $prompt, $userId);
    }
}
