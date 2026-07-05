<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\Tool\UploadedGeneratedImage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UploadedGeneratedImage::class)]
class UploadedGeneratedImageTest extends TestCase
{
    public function testConstructorStoresPublicUrl(): void
    {
        $image = new UploadedGeneratedImage('https://example.com/image.png');

        $this->assertSame('https://example.com/image.png', $image->publicUrl);
    }

    public function testToRichMarkdownWithEmptyCaption(): void
    {
        $image = new UploadedGeneratedImage('https://example.com/image.png');

        $this->assertSame('![](https://example.com/image.png)', $image->toRichMarkdown(''));
    }

    public function testToRichMarkdownWithCaption(): void
    {
        $image = new UploadedGeneratedImage('https://example.com/image.png');

        $this->assertSame('![](https://example.com/image.png "A nice image")', $image->toRichMarkdown('A nice image'));
    }

    public function testToRichMarkdownSanitizesSquareBracketsInCaption(): void
    {
        $image = new UploadedGeneratedImage('https://example.com/image.png');

        $result = $image->toRichMarkdown('Image [of] something');

        // Square brackets in CAPTION should be replaced with parentheses
        $this->assertStringContainsString('(of)', $result);
        // The markdown format itself uses [] for the link syntax
    }

    public function testToRichMarkdownSanitizesDoubleQuotesInCaption(): void
    {
        $image = new UploadedGeneratedImage('https://example.com/image.png');

        $result = $image->toRichMarkdown('A "quoted" caption');

        // Double quotes in CAPTION should be replaced with single quotes
        $this->assertStringContainsString("'quoted'", $result);
        // The markdown format uses " for the title delimiter
    }

    public function testToRichMarkdownSanitizesCarriageReturn(): void
    {
        $image = new UploadedGeneratedImage('https://example.com/image.png');

        $result = $image->toRichMarkdown('Line1\rLine2');

        $this->assertStringNotContainsString("\r", $result);
    }

    public function testToRichMarkdownSanitizesNewlines(): void
    {
        $image = new UploadedGeneratedImage('https://example.com/image.png');

        $result = $image->toRichMarkdown("Line1\nLine2");

        $this->assertStringNotContainsString("\n", $result);
    }

    public function testToRichMarkdownTrimsCaption(): void
    {
        $image = new UploadedGeneratedImage('https://example.com/image.png');

        $result = $image->toRichMarkdown('  trimmed  ');

        $this->assertStringNotContainsString('  trimmed', $result);
        $this->assertStringContainsString('trimmed', $result);
    }

    public function testPublicUrlIsReadonly(): void
    {
        $image = new UploadedGeneratedImage('https://example.com/image.png');

        $this->expectException(\Error::class);

        $image->publicUrl = 'https://other.com/';
    }
}
