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
        $expected = 'Hello `<invalid image: alt>`';
        $this->assertSame($expected, TelegramRichMarkdown::removeImages($input));
    }

    public function testSanitizeImageUrlReplacesImageWithNoAlt(): void
    {
        $input = 'Hello ![](https://example.com/image.png)';
        $expected = 'Hello `<invalid image>`';
        $this->assertSame($expected, TelegramRichMarkdown::removeImages($input));
    }

    public function testSanitizeImageUrlReplacesImageWithCaption(): void
    {
        $input = '![cat](https://cdn.bot.com/image.png "A cute cat")';
        $expected = '`<invalid image: cat>`';
        $this->assertSame($expected, TelegramRichMarkdown::removeImages($input));
    }

    public function testSanitizeImageUrlReplacesHallucinatedUrl(): void
    {
        $input = '![](http://bad-url-here)';
        $expected = '`<invalid image>`';
        $this->assertSame($expected, TelegramRichMarkdown::removeImages($input));
    }

    public function testSanitizeImageUrlReplacesMultipleImages(): void
    {
        $input = 'First ![a](https://a.com/1.png) and second ![b](https://b.com/2.jpg) done';
        $expected = 'First `<invalid image: a>` and second `<invalid image: b>` done';
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

    public function testRemoveImagesReplacesHtmlImgTag(): void
    {
        $input = 'Look <img src="https://example.com/image.png"> here';
        $expected = 'Look `<invalid image>` here';
        $this->assertSame($expected, TelegramRichMarkdown::removeImages($input));
    }

    public function testRemoveImagesReplacesHtmlImgTagWithAlt(): void
    {
        $input = '<img alt="hello" src="https://example.com/image.png">';
        $expected = '`<invalid image: hello>`';
        $this->assertSame($expected, TelegramRichMarkdown::removeImages($input));
    }

    public function testRemoveImagesReplacesHtmlImgTagAltAfterSrc(): void
    {
        $input = '<img src="https://example.com/image.png" alt="hello">';
        $expected = '`<invalid image: hello>`';
        $this->assertSame($expected, TelegramRichMarkdown::removeImages($input));
    }

    public function testRemoveImagesReplacesHtmlImgTagCaseInsensitive(): void
    {
        $input = '<IMG SRC="https://example.com/image.png">';
        $expected = '`<invalid image>`';
        $this->assertSame($expected, TelegramRichMarkdown::removeImages($input));
    }

    public function testRemoveImagesReplacesBothMarkdownAndHtmlImages(): void
    {
        $input = 'md ![a](https://a.com/1.png) html <img src="https://b.com/2.jpg">';
        $expected = 'md `<invalid image: a>` html `<invalid image>`';
        $this->assertSame($expected, TelegramRichMarkdown::removeImages($input));
    }

    public function testRemoveImagesKeepsContentInsidePairedImgTag(): void
    {
        $input = 'before <img>kept content</img> after';
        $expected = 'before kept content after';
        $this->assertSame($expected, TelegramRichMarkdown::removeImages($input));
    }

    public function testRemoveImagesKeepsContentInsidePairedImgTagWithAttributes(): void
    {
        $input = '<img src="https://example.com/image.png" alt="hello">inner text</img>';
        $expected = 'inner text';
        $this->assertSame($expected, TelegramRichMarkdown::removeImages($input));
    }

    public function testRemoveImagesKeepsMultilineContentInsidePairedImgTag(): void
    {
        $input = "<img>line1\nline2</img>";
        $expected = "line1\nline2";
        $this->assertSame($expected, TelegramRichMarkdown::removeImages($input));
    }

    public function testRemoveImagesPreservesImgTagInsideCodeSpan(): void
    {
        // Tool-call notifications wrap arguments in an inline code span; the
        // <img> tags there are literal and must survive unchanged.
        $input = "\n>Executing `image_gen_tool` with arguments `{\"prompt\":\"<img>#0</img>\"}`\n\n";
        $this->assertSame($input, TelegramRichMarkdown::removeImages($input));
    }

    public function testRemoveImagesPreservesMarkdownImageInsideCodeSpan(): void
    {
        $input = 'see `![a](https://example.com/x.png)` here';
        $this->assertSame($input, TelegramRichMarkdown::removeImages($input));
    }

    public function testRemoveImagesPreservesImagesInsideFencedCodeBlock(): void
    {
        $input = "intro\n```\n<img src=\"url\">\n![a](b)\n```\noutro";
        $this->assertSame($input, TelegramRichMarkdown::removeImages($input));
    }

    public function testRemoveImagesStripsImagesOutsideCodeButPreservesInside(): void
    {
        $input = '![a](https://a.com/1.png) `<img>x</img>` ![b](https://b.com/2.jpg)';
        $expected = '`<invalid image: a>` `<img>x</img>` `<invalid image: b>`';
        $this->assertSame($expected, TelegramRichMarkdown::removeImages($input));
    }
}
