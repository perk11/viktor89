<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Util;

use Perk11\Viktor89\ImageGeneration\ContentTypeGuesser;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Brings an image within what a vision API accepts. Formats outside the
 * accepted media types are re-encoded as PNG, and images over the byte or
 * dimension limits are downscaled and re-encoded as JPEG.
 */
final class ImageNormalizer
{
    /** Keeps the base64-encoded image under the Anthropic API's 5 MB per-image limit. */
    private const int DEFAULT_MAX_BYTES = 3_750_000;
    private const int DEFAULT_MAX_EDGE_PIXELS = 8000;
    private const int DEFAULT_RESIZED_EDGE_PIXELS = 2576;
    private const array JPEG_QUALITIES = [90, 75, 60];

    private const array MEDIA_TYPE_BY_EXTENSION = [
        'jpg'  => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
    ];

    /**
     * @param list<string> $acceptedMediaTypes must include image/png and image/jpeg, the re-encoding targets
     */
    public function __construct(
        private readonly array $acceptedMediaTypes,
        private readonly ?LoggerInterface $logger = null,
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
        private readonly int $maxEdgePixels = self::DEFAULT_MAX_EDGE_PIXELS,
        private readonly int $resizedEdgePixels = self::DEFAULT_RESIZED_EDGE_PIXELS,
    ) {
    }

    /** Returns null when the image cannot be decoded or brought under the limits. */
    public function normalize(string $image): ?NormalizedImage
    {
        $mediaType = self::MEDIA_TYPE_BY_EXTENSION[ContentTypeGuesser::guessFileExtension($image)] ?? null;
        $dimensions = @getimagesizefromstring($image);
        $isTooLarge = strlen($image) > $this->maxBytes
            || ($dimensions !== false && max($dimensions[0], $dimensions[1]) > $this->maxEdgePixels);

        if (!$isTooLarge && in_array($mediaType, $this->acceptedMediaTypes, true)) {
            return new NormalizedImage($image, $mediaType);
        }

        $gdImage = @imagecreatefromstring($image);
        if ($gdImage === false) {
            $this->logger?->log(LogLevel::ERROR, 'Failed to decode image, dropping it');
            return null;
        }

        if (!$isTooLarge) {
            $png = self::encodePng($gdImage);
            if (strlen($png) <= $this->maxBytes) {
                return new NormalizedImage($png, 'image/png');
            }
        }

        $width = imagesx($gdImage);
        $height = imagesy($gdImage);
        if (max($width, $height) > $this->resizedEdgePixels) {
            $scale = $this->resizedEdgePixels / max($width, $height);
            $gdImage = imagescale($gdImage, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            if ($gdImage === false) {
                $this->logger?->log(LogLevel::ERROR, 'Failed to downscale image, dropping it');
                return null;
            }
        }

        foreach (self::JPEG_QUALITIES as $quality) {
            $jpeg = self::encodeJpeg($gdImage, $quality);
            if (strlen($jpeg) <= $this->maxBytes) {
                return new NormalizedImage($jpeg, 'image/jpeg');
            }
        }
        $this->logger?->log(LogLevel::ERROR, 'Image is still over the size limit after re-encoding, dropping it');

        return null;
    }

    private static function encodePng(\GdImage $image): string
    {
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private static function encodeJpeg(\GdImage $image, int $quality): string
    {
        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }
}
