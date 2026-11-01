<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Test\Support\TestImages;
use Perk11\Viktor89\Util\ImageNormalizer;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/AssistantTestFixtures.php';

class ImageNormalizerTest extends TestCase
{
    public function testAcceptedImageIsReturnedUnchanged(): void
    {
        $webp = TestImages::webp(4, 4);

        $normalized = (new ImageNormalizer(['image/jpeg', 'image/png', 'image/webp']))->normalize($webp);

        $this->assertSame('image/webp', $normalized->mediaType);
        $this->assertSame($webp, $normalized->bytes);
        $this->assertSame('data:image/webp;base64,' . base64_encode($webp), $normalized->toDataUrl());
    }

    public function testUnsupportedFormatIsReencodedAsPng(): void
    {
        $normalized = (new ImageNormalizer(['image/jpeg', 'image/png']))->normalize(TestImages::webp(4, 4));

        $this->assertSame('image/png', $normalized->mediaType);
        $this->assertSame([4, 4], array_slice(getimagesizefromstring($normalized->bytes), 0, 2));
    }

    public function testImageWithTooLongEdgeIsDownscaledToJpeg(): void
    {
        $normalized = (new ImageNormalizer(['image/jpeg', 'image/png']))->normalize(TestImages::png(8100, 10));

        $this->assertSame('image/jpeg', $normalized->mediaType);
        [$width] = getimagesizefromstring($normalized->bytes);
        $this->assertSame(2576, $width);
    }

    public function testImageOverByteLimitIsReencodedAsJpeg(): void
    {
        $png = self::noisePng(64, 64);
        $normalizer = new ImageNormalizer(['image/jpeg', 'image/png'], maxBytes: strlen($png) - 1);

        $normalized = $normalizer->normalize($png);

        $this->assertSame('image/jpeg', $normalized->mediaType);
        $this->assertLessThan(strlen($png), strlen($normalized->bytes));
    }

    public function testImageThatCannotBeBroughtUnderByteLimitIsDropped(): void
    {
        $normalizer = new ImageNormalizer(['image/jpeg', 'image/png'], maxBytes: 10);

        $this->assertNull($normalizer->normalize(self::noisePng(64, 64)));
    }

    public function testUndecodableImageIsDropped(): void
    {
        $this->assertNull((new ImageNormalizer(['image/jpeg', 'image/png']))->normalize('not an image'));
    }

    private static function noisePng(int $width, int $height): string
    {
        mt_srand(89);
        $image = imagecreatetruecolor($width, $height);
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                imagesetpixel($image, $x, $y, mt_rand(0, 0xFFFFFF));
            }
        }
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
