<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\ImageGeneration\ContentTypeGuesser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentTypeGuesser::class)]
class ContentTypeGuesserTest extends TestCase
{
    public function testGuessesJpegFromMagicBytes(): void
    {
        $jpegData = "\xFF\xD8\xFF" . str_repeat('x', 100);
        $this->assertSame('jpg', ContentTypeGuesser::guessFileExtension($jpegData));
    }

    public function testGuessesPngFromMagicBytes(): void
    {
        $pngData = "\x89PNG\r\n\x1A\n" . str_repeat('x', 100);
        $this->assertSame('png', ContentTypeGuesser::guessFileExtension($pngData));
    }

    public function testGuessesWebpFromMagicBytes(): void
    {
        $webpData = 'RIFF' . "xxxx" . 'WEBP' . str_repeat('x', 100);
        $this->assertSame('webp', ContentTypeGuesser::guessFileExtension($webpData));
    }

    public function testReturnsUnknownForShortJpegData(): void
    {
        $shortData = "\xFF\xD8"; // Only 2 bytes, needs 3
        $this->assertSame('unknown', ContentTypeGuesser::guessFileExtension($shortData));
    }

    public function testReturnsUnknownForShortPngData(): void
    {
        $shortData = "\x89PNG"; // Only 4 bytes, needs 8
        $this->assertSame('unknown', ContentTypeGuesser::guessFileExtension($shortData));
    }

    public function testReturnsUnknownForShortWebpData(): void
    {
        $shortData = 'RIFFxxxWE'; // Only 9 bytes, needs 12
        $this->assertSame('unknown', ContentTypeGuesser::guessFileExtension($shortData));
    }

    public function testReturnsUnknownForRandomData(): void
    {
        $randomData = str_repeat('a', 200);
        $this->assertSame('unknown', ContentTypeGuesser::guessFileExtension($randomData));
    }

    public function testReturnsUnknownForEmptyString(): void
    {
        $this->assertSame('unknown', ContentTypeGuesser::guessFileExtension(''));
    }

    public function testReturnsUnknownForTextContent(): void
    {
        $this->assertSame('unknown', ContentTypeGuesser::guessFileExtension('Hello, world!'));
    }

    public function testGuessesJpegWithMinimalValidData(): void
    {
        $minimalJpeg = "\xFF\xD8\xFF";
        $this->assertSame('jpg', ContentTypeGuesser::guessFileExtension($minimalJpeg));
    }

    public function testGuessesPngWithMinimalValidData(): void
    {
        $minimalPng = "\x89PNG\r\n\x1A\n";
        $this->assertSame('png', ContentTypeGuesser::guessFileExtension($minimalPng));
    }

    public function testGuessesWebpWithMinimalValidData(): void
    {
        // WebP requires: RIFF at positions 0-3, WEBP at positions 8-11 = 12 bytes total
        $minimalWebp = 'RIFF' . "\x00\x00\x00\x00" . 'WEBP';
        $this->assertSame('webp', ContentTypeGuesser::guessFileExtension($minimalWebp));
    }
}
