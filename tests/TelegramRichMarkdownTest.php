<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Util\TelegramRichMarkdown;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TelegramRichMarkdown::class)]
class TelegramRichMarkdownTest extends TestCase
{
    public function testSanitizeImageUrlReplacesAllImages(): void
    {
        $input = 'Hello ![alt](https://example.com/image.jpg)';
        $expected = 'Hello ![alt](<invalid image>)';
        $this->assertSame($expected, TelegramRichMarkdown::removeImages($input));
    }

    public function testSanitizeImageUrlReplacesImageWithNoAlt(): void
    {
        $input = 'Hello ![](https://example.com/image.png)';
        $expected = 'Hello ![](<invalid image>)';
        $this->assertSame($expected, TelegramRichMarkdown::removeImages($input));
    }

    public function testSanitizeImageUrlReplacesImageWithCaption(): void
    {
        $input = '![cat](https://cdn.bot.com/image.png "A cute cat")';
        $expected = '![cat](<invalid image>)';
        $this->assertSame($expected, TelegramRichMarkdown::removeImages($input));
    }

    public function testSanitizeImageUrlReplacesHallucinatedUrl(): void
    {
        $input = '![](http://bad-url-here)';
        $expected = '![](<invalid image>)';
        $this->assertSame($expected, TelegramRichMarkdown::removeImages($input));
    }

    public function testSanitizeImageUrlReplacesMultipleImages(): void
    {
        $input = 'First ![a](https://a.com/1.png) and second ![b](https://b.com/2.jpg) done';
        $expected = 'First ![a](<invalid image>) and second ![b](<invalid image>) done';
        $this->assertSame($expected, TelegramRichMarkdown::removeImages($input));
    }

    public function testSanitizeImageUrlPreservesNonImageText(): void
    {
        $input = 'This is **bold** and *italic* with `code`.';
        $this->assertSame($input, TelegramRichMarkdown::removeImages($input));
    }

    public function testSanitizeImageUrlEmptyString(): void
    {
        $this->assertSame('', TelegramRichMarkdown::removeImages(''));
    }
}
