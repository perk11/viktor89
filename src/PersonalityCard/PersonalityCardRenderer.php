<?php

declare(strict_types=1);

namespace Perk11\Viktor89\PersonalityCard;

use GdImage;
use RuntimeException;

/**
 * Renders a PersonalityCard onto a generated portrait as a collectible trading
 * card (PNG) using GD: rarity-coloured frame, name plate, archetype, stat bars,
 * flavour quote and a power/rarity footer.
 */
final class PersonalityCardRenderer
{
    private const WIDTH = 760;
    private const HEIGHT = 1120;
    private const MARGIN = 28;

    private const PORTRAIT_W = 704;
    private const PORTRAIT_H = 600;
    private const FRAME_THICKNESS = 6;

    private const BAR_MAX = 360;

    /** stat key => display label, in display order */
    private const STATS = [
        'charisma'  => 'Charisma',
        'chaos'     => 'Chaos',
        'brainrot'  => 'Brainrot',
        'wholesome' => 'Wholesome',
        'menace'    => 'Menace',
    ];

    private const FONT_BOLD = 'DejaVuSans-Bold.ttf';
    private const FONT_REGULAR = 'DejaVuSans.ttf';
    private const FONT_SERIF = 'DejaVuSerif-Bold.ttf';

    public function render(PersonalityCard $card, string $portraitBytes): string
    {
        $fontBold = self::font(self::FONT_BOLD);
        $fontRegular = self::font(self::FONT_REGULAR);
        $fontSerif = self::font(self::FONT_SERIF);

        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

        [$r, $g, $b] = PersonalityCardRarity::color($card->rarity);
        $accent = imagecolorallocate($canvas, $r, $g, $b);
        $accentSoft = imagecolorallocate($canvas, (int) ($r * 0.45 + 20), (int) ($g * 0.45 + 18), (int) ($b * 0.45 + 26));
        $white = imagecolorallocate($canvas, 246, 246, 252);
        $muted = imagecolorallocate($canvas, 184, 188, 202);
        $dim = imagecolorallocate($canvas, 110, 114, 132);
        $darkPanel = imagecolorallocate($canvas, (int) ($r * 0.32 + 8), (int) ($g * 0.32 + 8), (int) ($b * 0.32 + 14));
        $barBg = imagecolorallocate($canvas, 26, 28, 44);

        $this->drawBackground($canvas);

        $portrait = @imagecreatefromstring($portraitBytes);
        if (!$portrait instanceof GdImage) {
            // Image model returned something unparseable: paint a placeholder so the card still ships.
            $portrait = imagecreatetruecolor(self::PORTRAIT_W, self::PORTRAIT_H);
            imagefilledrectangle($portrait, 0, 0, self::PORTRAIT_W, self::PORTRAIT_H, $darkPanel);
        }

        $px = self::MARGIN;
        $py = self::MARGIN;
        $this->drawPortrait($canvas, $portrait, $px, $py, $accent, $accentSoft);
        imagedestroy($portrait);

        // Name plate, sitting just under the portrait frame.
        $plateTop = $py + self::PORTRAIT_H + self::FRAME_THICKNESS;
        $plateBottom = $plateTop + 66;
        imagefilledrectangle($canvas, $px, $plateTop, $px + self::PORTRAIT_W, $plateBottom, $darkPanel);
        imagerectangle($canvas, $px, $plateTop, $px + self::PORTRAIT_W, $plateBottom, $accentSoft);

        $name = $card->name !== '' ? $card->name : 'Unknown Hero';
        $nameSize = $this->fitSize($name, $fontSerif, 30, 20, self::PORTRAIT_W - 40);
        $this->drawCentered($canvas, $name, $nameSize, $fontSerif, $white, $px, $px + self::PORTRAIT_W, $plateTop + 44);

        // Archetype subtitle.
        $archY = $plateBottom + 26;
        $this->drawCentered($canvas, $this->upper($card->archetype), 18, $fontBold, $accent, $px, $px + self::PORTRAIT_W, $archY);

        // Divider.
        $dividerY = $archY + 22;
        imageline($canvas, $px + 36, $dividerY, $px + self::PORTRAIT_W - 36, $dividerY, $accentSoft);

        // Stat bars.
        $rowTop = $dividerY + 22;
        $rowHeight = 34;
        $labelX = $px + 16;
        $barX = $px + 196;
        $valueRight = $px + self::PORTRAIT_W - 16;
        $i = 0;
        foreach (self::STATS as $key => $label) {
            $value = isset($card->stats[$key]) ? max(0, min(10, (int) $card->stats[$key])) : 0;
            $baseline = $rowTop + $i * $rowHeight + 22;
            $this->drawLeft($canvas, $label, 17, $fontBold, $white, $labelX, $baseline);

            $barY = $baseline - 22 + 9;
            imagefilledrectangle($canvas, $barX, $barY, $barX + self::BAR_MAX, $barY + 16, $barBg);
            $fillW = (int) (self::BAR_MAX * ($value / 10));
            if ($fillW > 0) {
                imagefilledrectangle($canvas, $barX, $barY, $barX + $fillW, $barY + 16, $accent);
            }
            imagerectangle($canvas, $barX, $barY, $barX + self::BAR_MAX, $barY + 16, $accentSoft);

            $valueText = (string) $value;
            $this->drawRight($canvas, $valueText, 17, $fontBold, $white, $valueRight, $baseline);
            $i++;
        }

        // Flavour quote.
        $quoteTop = $rowTop + count(self::STATS) * $rowHeight + 18;
        if (trim($card->quote) !== '') {
            $quote = '“' . trim($card->quote) . '”';
            $this->drawWrapped($canvas, $quote, 16, $fontRegular, $muted, $px + 16, $px + self::PORTRAIT_W - 16, $quoteTop, 20, 3);
        }

        // Footer: stars · rarity · power · card number.
        $footerY = self::HEIGHT - 34;
        $footer = str_repeat('★', max(1, $card->stars))
            . '  ' . PersonalityCardRarity::label($card->rarity)
            . '   ⚡ ' . $card->power
            . '   ' . $card->cardNumber;
        $this->drawCentered($canvas, $footer, 15, $fontBold, $accent, $px, $px + self::PORTRAIT_W, $footerY);

        return $this->toPng($canvas);
    }

    private function drawPortrait(GdImage $canvas, GdImage $portrait, int $x, int $y, int $border, int $inner): void
    {
        // Solid rarity border, then the portrait inset by the border thickness.
        imagefilledrectangle(
            $canvas,
            $x - self::FRAME_THICKNESS,
            $y - self::FRAME_THICKNESS,
            $x + self::PORTRAIT_W + self::FRAME_THICKNESS,
            $y + self::PORTRAIT_H + self::FRAME_THICKNESS,
            $border,
        );
        $this->placeCenterCropped($canvas, $portrait, $x, $y, self::PORTRAIT_W, self::PORTRAIT_H);
        imagerectangle($canvas, $x, $y, $x + self::PORTRAIT_W - 1, $y + self::PORTRAIT_H - 1, $inner);
    }

    private function placeCenterCropped(GdImage $canvas, GdImage $src, int $dx, int $dy, int $dw, int $dh): void
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $srcAspect = $sw / $sh;
        $dstAspect = $dw / $dh;

        if ($srcAspect > $dstAspect) {
            // Source is wider: crop the sides.
            $usedW = (int) round($sh * $dstAspect);
            $sx = (int) (($sw - $usedW) / 2);
            $sy = 0;
            $usedH = $sh;
        } else {
            $usedH = (int) round($sw / $dstAspect);
            $sy = (int) (($sh - $usedH) / 2);
            $sx = 0;
            $usedW = $sw;
        }

        imagecopyresampled($canvas, $src, $dx, $dy, $sx, $sy, $dw, $dh, $usedW, $usedH);
    }

    private function drawBackground(GdImage $canvas): void
    {
        $top = [10, 12, 26];
        $bot = [30, 16, 46];
        for ($y = 0; $y < self::HEIGHT; $y++) {
            $t = $y / self::HEIGHT;
            $r = (int) ($top[0] + ($bot[0] - $top[0]) * $t);
            $g = (int) ($top[1] + ($bot[1] - $top[1]) * $t);
            $b = (int) ($top[2] + ($bot[2] - $top[2]) * $t);
            imageline($canvas, 0, $y, self::WIDTH, $y, imagecolorallocate($canvas, $r, $g, $b));
        }

        // Deterministic starfield confined to the area below the portrait.
        mt_srand(20240713);
        $dot = imagecolorallocate($canvas, 70, 74, 98);
        $regionTop = self::MARGIN + self::PORTRAIT_H + 80;
        for ($i = 0; $i < 130; $i++) {
            $x = mt_rand(0, self::WIDTH - 1);
            $y = mt_rand($regionTop, self::HEIGHT - 12);
            imagesetpixel($canvas, $x, $y, $dot);
        }
        mt_srand(); // restore
    }

    private function drawCentered(GdImage $canvas, string $text, float $size, string $font, int $color, int $left, int $right, int $baselineY): void
    {
        $width = $this->textWidth($text, $size, $font);
        $x = $left + (int) round(($right - $left - $width) / 2);
        imagettftext($canvas, $size, 0, max($left, $x), $baselineY, $color, $font, $text);
    }

    private function drawLeft(GdImage $canvas, string $text, float $size, string $font, int $color, int $x, int $baselineY): void
    {
        imagettftext($canvas, $size, 0, $x, $baselineY, $color, $font, $text);
    }

    private function drawRight(GdImage $canvas, string $text, float $size, string $font, int $color, int $rightX, int $baselineY): void
    {
        $width = $this->textWidth($text, $size, $font);
        imagettftext($canvas, $size, 0, $rightX - $width, $baselineY, $color, $font, $text);
    }

    /**
     * @param string[] $out
     */
    private function drawWrapped(GdImage $canvas, string $text, float $size, string $font, int $color, int $left, int $right, int $baselineY, int $lineHeight, int $maxLines): void
    {
        $maxWidth = $right - $left;
        $lines = $this->wrap($text, $size, $font, $maxWidth);
        $y = $baselineY;
        foreach ($lines as $i => $line) {
            if ($i >= $maxLines) {
                break;
            }
            imagettftext($canvas, $size, 0, $left, $y, $color, $font, $line);
            $y += $lineHeight;
        }
    }

    /** @return string[] */
    private function wrap(string $text, float $size, string $font, int $maxWidth): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($this->textWidth($candidate, $size, $font) <= $maxWidth) {
                $current = $candidate;
            } else {
                if ($current !== '') {
                    $lines[] = $current;
                }
                $current = $word;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    private function textWidth(string $text, float $size, string $font): int
    {
        $box = imagettfbbox($size, 0, $font, $text);
        if ($box === false) {
            return 0;
        }

        return abs($box[2] - $box[0]);
    }

    /**
     * Shrink the font size so $text fits within $maxWidth, down to $minSize.
     */
    private function fitSize(string $text, string $font, float $size, float $minSize, int $maxWidth): float
    {
        while ($size > $minSize && $this->textWidth($text, $size, $font) > $maxWidth) {
            $size -= 1.0;
        }

        return $size;
    }

    private function upper(string $text): string
    {
        return mb_strtoupper(trim($text));
    }

    private function toPng(GdImage $canvas): string
    {
        ob_start();
        imagepng($canvas);
        $png = (string) ob_get_clean();
        imagedestroy($canvas);

        return $png;
    }

    private static function font(string $basename): string
    {
        $candidates = [
            __DIR__ . '/fonts/' . $basename,
            '/usr/share/fonts/truetype/dejavu/' . $basename,
            '/usr/share/fonts/dejavu/' . $basename,
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException("Personality card font not found: $basename");
    }
}
