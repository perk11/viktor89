<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\ImageGeneration\ImgTagExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImgTagExtractor::class)]
class ImgTagExtractorTest extends TestCase
{
    public function testExtractsSingleImgTag(): void
    {
        $input = '<img src="http://example.com/image.jpg">';
        $extracted = $this->extractImgTags($input);
        $this->assertCount(1, $extracted);
        $this->assertSame('http://example.com/image.jpg', $extracted[0]);
    }

    public function testExtractsMultipleImgTags(): void
    {
        $input = '<img src="http://a.com/1.jpg"><img src="http://b.com/2.jpg">';
        $extracted = $this->extractImgTags($input);
        $this->assertCount(2, $extracted);
    }

    public function testNoImgTagsReturnsEmpty(): void
    {
        $input = 'just some text';
        $extracted = $this->extractImgTags($input);
        $this->assertCount(0, $extracted);
    }

    public function testIgnoresOtherTags(): void
    {
        $input = '<p>hello</p><img src="http://test.com/img.png">';
        $extracted = $this->extractImgTags($input);
        $this->assertCount(1, $extracted);
    }

    private function extractImgTags(string $text): array
    {
        $matches = [];
        preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $text, $matches);
        return $matches[1] ?? [];
    }
}
