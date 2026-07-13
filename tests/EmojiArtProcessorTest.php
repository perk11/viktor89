<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\EmojiArt\EmojiArtProcessor;
use Perk11\Viktor89\EmojiArt\EmojiPalette;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\TelegramFileDownloader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagefilledrectangle;
use function imagepng;

#[CoversClass(EmojiArtProcessor::class)]
class EmojiArtProcessorTest extends TestCase
{
    public function testImplementsMessageChainProcessor(): void
    {
        $reflection = new \ReflectionClass(EmojiArtProcessor::class);
        $this->assertFalse($reflection->isInterface());
        $this->assertFalse($reflection->isAbstract());
        $this->assertTrue($reflection->implementsInterface(MessageChainProcessor::class));
    }

    public function testConstructorTakesTelegramFileDownloaderAndPalette(): void
    {
        $params = (new \ReflectionClass(EmojiArtProcessor::class))->getConstructor()->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame(TelegramFileDownloader::class, $params[0]->getType()->getName());
        $this->assertSame(EmojiPalette::class, $params[1]->getType()->getName());
    }

    public function testComputeGridClampsWidthToAllowedRange(): void
    {
        $processor = $this->buildProcessor();

        [$minCols] = $processor->computeGrid(2, 100, 100);
        $this->assertSame(8, $minCols);

        // A wide, short image stays under the cell cap, so the width clamp wins.
        [$maxCols] = $processor->computeGrid(999, 100, 10);
        $this->assertSame(48, $maxCols);
    }

    public function testComputeGridPreservesAspectRatio(): void
    {
        $processor = $this->buildProcessor();

        [, $rows] = $processor->computeGrid(20, 100, 50);
        $this->assertSame(10, $rows);

        [, $rows] = $processor->computeGrid(20, 50, 100);
        $this->assertSame(40, $rows);
    }

    public function testComputeGridShrinksToCellCapForExtremeAspect(): void
    {
        $processor = $this->buildProcessor();

        [$cols, $rows] = $processor->computeGrid(48, 100, 800);
        // Without the cap this would be 48 * 384 cells; the cap must kick in.
        $this->assertLessThanOrEqual(1500, $cols * $rows);
        $this->assertGreaterThanOrEqual(1, $rows);
    }

    public function testBuildMosaicProducesGridWithOneLinePerRow(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension not available');
        }

        $png = $this->renderPng(8, 8, 220, 40, 40);

        $mosaic = $this->buildProcessor()->buildMosaic($png, 8);
        $lines = explode("\n", $mosaic);

        $this->assertCount(8, $lines);
        $this->assertNotSame('', $lines[0]);
    }

    public function testBuildMosaicMapsSolidColorsDeterministically(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension not available');
        }

        $red = $this->renderPng(8, 8, 215, 45, 45);
        $processor = $this->buildProcessor();
        $redMosaic = $processor->buildMosaic($red, 8);

        // A solid red image is entirely mapped to the red anchor emoji.
        $this->assertSame(str_repeat('🟥', 8), explode("\n", $redMosaic)[0]);
        $this->assertSame($redMosaic, $processor->buildMosaic($red, 8));
    }

    public function testBuildMosaicThrowsOnInvalidImageData(): void
    {
        $this->expectException(\Exception::class);
        $this->buildProcessor()->buildMosaic('not an image', 16);
    }

    private function buildProcessor(): EmojiArtProcessor
    {
        return new EmojiArtProcessor(
            $this->createStub(TelegramFileDownloader::class),
            new EmojiPalette(),
        );
    }

    private function renderPng(int $width, int $height, int $r, int $g, int $b): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, $r, $g, $b));
        ob_start();
        imagepng($image);
        $data = (string) ob_get_clean();

        return $data;
    }
}
