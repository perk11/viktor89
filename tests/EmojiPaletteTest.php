<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\EmojiArt\EmojiPalette;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmojiPalette::class)]
class EmojiPaletteTest extends TestCase
{
    public function testFindNearestMatchesExactAnchorColors(): void
    {
        $palette = new EmojiPalette();

        // Pure black / white resolve to their exact palette anchors.
        $this->assertSame('⬛', $palette->findNearest(0, 0, 0));
        $this->assertSame('⬜', $palette->findNearest(255, 255, 255));
    }

    public function testFindNearestPicksClosestHue(): void
    {
        $palette = new EmojiPalette();

        $this->assertSame($palette->findNearest(255, 0, 0), $palette->findNearest(250, 5, 5));
        $this->assertNotSame(
            $palette->findNearest(255, 0, 0),
            $palette->findNearest(0, 0, 255),
        );
    }

    public function testFindNearestRespectsCustomPalette(): void
    {
        $palette = new EmojiPalette([
            ['emoji' => '🔴', 'r' => 200, 'g' => 0, 'b' => 0],
            ['emoji' => '🟢', 'r' => 0, 'g' => 200, 'b' => 0],
        ]);

        $this->assertSame('🔴', $palette->findNearest(180, 10, 10));
        $this->assertSame('🟢', $palette->findNearest(10, 180, 10));
    }

    public function testColorDistanceIsZeroForIdenticalColors(): void
    {
        $this->assertSame(0.0, EmojiPalette::colorDistance(123, 45, 67, 123, 45, 67));
    }

    public function testColorDistanceOrdersByPerceptualCloseness(): void
    {
        // Pure red is perceptually closer to orange than to blue.
        $toOrange = EmojiPalette::colorDistance(255, 0, 0, 255, 140, 0);
        $toBlue = EmojiPalette::colorDistance(255, 0, 0, 0, 0, 255);

        $this->assertLessThan($toBlue, $toOrange);
    }

    public function testDefaultPaletteIsNonEmptyAndWellFormed(): void
    {
        $entries = (new EmojiPalette())->all();

        $this->assertNotEmpty($entries);
        foreach ($entries as $entry) {
            $this->assertIsString($entry['emoji']);
            $this->assertNotSame('', $entry['emoji']);
            foreach (['r', 'g', 'b'] as $channel) {
                $this->assertIsInt($entry[$channel]);
                $this->assertGreaterThanOrEqual(0, $entry[$channel]);
                $this->assertLessThanOrEqual(255, $entry[$channel]);
            }
        }
    }
}
