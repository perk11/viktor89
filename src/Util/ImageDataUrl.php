<?php

namespace Perk11\Viktor89\Util;

use Perk11\Viktor89\ImageGeneration\ContentTypeGuesser;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

final class ImageDataUrl
{
    /**
     * Convert a raw image blob into an OpenAI image_url content part (data URL),
     * converting webp to png because chat APIs reject webp data URLs. Returns
     * null when the blob cannot be encoded.
     *
     * @return array{type: string, image_url: array{url: string}}|null
     */
    public static function buildContentPart(string $image, ?LoggerInterface $logger = null): ?array
    {
        $url = self::build($image, $logger);
        if ($url === null) {
            return null;
        }

        return [
            'type'      => 'image_url',
            'image_url' => ['url' => $url],
        ];
    }

    public static function build(string $image, ?LoggerInterface $logger = null): ?string
    {
        switch (ContentTypeGuesser::guessFileExtension($image)) {
            case 'jpg':
                return 'data:image/jpeg;base64,' . base64_encode($image);
            case 'png':
                return 'data:image/png;base64,' . base64_encode($image);
            case 'webp':
                $gdImage = imagecreatefromstring($image);
                if ($gdImage === false) {
                    $logger?->log(LogLevel::ERROR, 'Failed to create image from webp');
                    return null;
                }
                ob_start();
                imagepng($gdImage);

                return 'data:image/png;base64,' . base64_encode(ob_get_clean());
        }

        return null;
    }
}
