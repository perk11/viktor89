<?php

declare(strict_types=1);

namespace Perk11\Viktor89\PersonalityCard;

use GdImage;
use RuntimeException;

/**
 * Renders a PersonalityCard onto a generated portrait as a collectible trading
 * card (PNG) using GD: rarity-coloured frame, name plate, archetype, stat bars,
 * a signature ability + special ability + weakness block and a power/rarity/
 * element footer. All visible text is Russian and emoji-free (DejaVu fonts carry
 * no emoji glyphs); quoted effect text uses straight ASCII quotes.
 */
final class PersonalityCardRenderer
{
    private const WIDTH = 880;
    private const HEIGHT = 1180;
    private const MARGIN = 28;

    private const PORTRAIT_W = 824;
    private const PORTRAIT_H = 600;
    private const FRAME_THICKNESS = 6;

    private const BAR_MAX = 580;

    /** Alpha (0 opaque .. 127 transparent) of the dark scrim painted behind text for legibility over arbitrary backgrounds. */
    private const TEXT_SCRIM_ALPHA = 40;
    private const SCRIM_PAD_X = 6;
    private const SCRIM_PAD_Y = 4;

    /** stat key => display label, in display order */
    private const STATS = [
        'wit'    => 'Остроумие',
        'chaos'  => 'Хаос',
        'wisdom' => 'Мудрость',
        'menace' => 'Дерзость',
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
        imagealphablending($canvas, false);

        [$r, $g, $b] = PersonalityCardRarity::color($card->rarity);
        $accent = imagecolorallocate($canvas, $r, $g, $b);
        $accentSoft = imagecolorallocate($canvas, (int) ($r * 0.45 + 20), (int) ($g * 0.45 + 18), (int) ($b * 0.45 + 26));
        $white = imagecolorallocate($canvas, 246, 246, 252);
        $muted = imagecolorallocate($canvas, 184, 188, 202);
        $darkPanel = imagecolorallocate($canvas, (int) ($r * 0.32 + 8), (int) ($g * 0.32 + 8), (int) ($b * 0.32 + 14));
        $barBg = imagecolorallocate($canvas, 26, 28, 44);
        $scrim = imagecolorallocatealpha($canvas, 12, 14, 28, self::TEXT_SCRIM_ALPHA);

        $this->drawBackground($canvas, $card->rarity);

        $portrait = @imagecreatefromstring($portraitBytes);
        if (!$portrait instanceof GdImage) {
            // Image model returned something unparseable: paint a placeholder so the card still ships.
            $portrait = imagecreatetruecolor(self::PORTRAIT_W, self::PORTRAIT_H);
            imagefilledrectangle($portrait, 0, 0, self::PORTRAIT_W, self::PORTRAIT_H, $darkPanel);
        }

        $px = self::MARGIN;
        $py = self::MARGIN;
        $this->drawPortrait($canvas, $portrait, $px, $py, $accent, $accentSoft, $darkPanel);

        // Name plate, sitting just under the portrait frame with a little breathing room above it.
        $plateTop = $py + self::PORTRAIT_H + self::FRAME_THICKNESS + 16;
        $plateBottom = $plateTop + 58;
        $this->paintScrim($canvas, $px, $plateTop, $px + self::PORTRAIT_W, $plateBottom, $scrim);
        imagerectangle($canvas, $px, $plateTop, $px + self::PORTRAIT_W, $plateBottom, $accentSoft);

        $name = $card->name !== '' ? $card->name : 'Unknown Hero';
        $nameSize = $this->fitSize($name, $fontSerif, 30, 20, self::PORTRAIT_W - 40);
        $this->drawCentered($canvas, $name, $nameSize, $fontSerif, $white, $px, $px + self::PORTRAIT_W, $plateTop + 40);

        // Archetype subtitle.
        $archY = $plateBottom + 28;
        $this->drawCentered($canvas, $this->upper($card->archetype), 18, $fontBold, $accent, $px, $px + self::PORTRAIT_W, $archY, $scrim);

        // Divider.
        $dividerY = $archY + 10;
        imageline($canvas, $px + 36, $dividerY, $px + self::PORTRAIT_W - 36, $dividerY, $accentSoft);

        // Stat bars.
        $rowTop = $dividerY + 18;
        $rowHeight = 28;
        $labelX = $px + 16;
        $barX = $px + 196;
        $valueRight = $px + self::PORTRAIT_W - 16;
        $i = 0;
        foreach (self::STATS as $key => $label) {
            $value = isset($card->stats[$key]) ? max(0, min(10, (int) $card->stats[$key])) : 0;
            $baseline = $rowTop + $i * $rowHeight + 22;
            $this->drawLeft($canvas, $label, 17, $fontBold, $white, $labelX, $baseline, $scrim);

            $barY = $baseline - 22 + 9;
            imagefilledrectangle($canvas, $barX, $barY, $barX + self::BAR_MAX, $barY + 16, $barBg);
            $fillW = (int) (self::BAR_MAX * ($value / 10));
            if ($fillW > 0) {
                imagefilledrectangle($canvas, $barX, $barY, $barX + $fillW, $barY + 16, $accent);
            }
            imagerectangle($canvas, $barX, $barY, $barX + self::BAR_MAX, $barY + 16, $accentSoft);

            $valueText = (string) $value;
            $this->drawRight($canvas, $valueText, 17, $fontBold, $white, $valueRight, $baseline, $scrim);
            $i++;
        }

        // Signature ability + special (ultimate) ability + weakness, flowing top-to-bottom.
        $y = $rowTop + count(self::STATS) * $rowHeight + 40;
        $flavorLeft = $px + 16;
        $flavorRight = $px + self::PORTRAIT_W - 16;
        $flavorWidth = $flavorRight - $flavorLeft;
        $headerSize = 15;
        $bodySize = 14;
        $bodyLineHeight = 17;
        $blockGap = 28;

        $y = $this->drawAbility($canvas, 'Способность: ', $card->ability, $card->abilityEffect, $fontBold, $fontRegular, $white, $muted, $flavorLeft, $flavorRight, $flavorWidth, $y, $headerSize, $bodySize, $bodyLineHeight, false, $scrim) + $blockGap;
        $y = $this->drawAbility($canvas, 'Особое умение: ', $card->specialAbility, $card->specialAbilityQuote, $fontBold, $fontRegular, $white, $muted, $flavorLeft, $flavorRight, $flavorWidth, $y, $headerSize, $bodySize, $bodyLineHeight, true, $scrim) + $blockGap;

        // Weakness: header line + indented body, filling whatever space remains above the footer (up to 3 lines).
        if (trim($card->weakness) !== '') {
            $this->drawLeft($canvas, 'Слабость', $headerSize, $fontBold, $white, $flavorLeft, $y, $scrim);
            $y += (int) ($headerSize + 8);
            $footerY = self::HEIGHT - 34;
            $remaining = max(0, $footerY - $y - 6);
            $maxLines = max(1, min(3, (int) floor($remaining / $bodyLineHeight)));
            $this->drawWrapped($canvas, trim($card->weakness), $bodySize, $fontRegular, $muted, $flavorLeft + 14, $flavorRight, $y, $bodyLineHeight, $maxLines, $scrim);
        }

        // Footer: stars · rarity · element · power · card number
        $footerY = self::HEIGHT - 10;
        $footer = str_repeat('★', max(1, $card->stars))
            . '  ' . PersonalityCardRarity::label($card->rarity)
            . '   ' . PersonalityCardElement::label($card->element)
            . '   Мощь ' . $card->power
            . '   ' . $card->cardNumber;
        $this->drawCentered($canvas, $footer, 15, $fontBold, $accent, $px, $px + self::PORTRAIT_W, $footerY, $scrim);

        return $this->toPng($canvas);
    }

    private function drawPortrait(GdImage $canvas, GdImage $portrait, int $x, int $y, int $border, int $inner, int $backdrop): void
    {
        // Solid rarity border, then an opaque backdrop filling the allocated space so
        // the portrait can never leave transparent gaps (e.g. sources with alpha),
        // then the portrait inset by the border thickness, cover-cropped to fill it.
        imagefilledrectangle(
            $canvas,
            $x - self::FRAME_THICKNESS,
            $y - self::FRAME_THICKNESS,
            $x + self::PORTRAIT_W + self::FRAME_THICKNESS,
            $y + self::PORTRAIT_H + self::FRAME_THICKNESS,
            $border,
        );
        imagefilledrectangle($canvas, $x, $y, $x + self::PORTRAIT_W, $y + self::PORTRAIT_H, $backdrop);
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

    private function drawBackground(GdImage $canvas, string $rarity): void
    {
        $path = self::background($rarity);
        if (is_file($path)) {
            $bg = @imagecreatefromstring((string) file_get_contents($path));
            if ($bg instanceof GdImage) {
                // Background PNGs are 880x1180; cover-crop so an off-spec source still fills the card.
                $this->placeCenterCropped($canvas, $bg, 0, 0, self::WIDTH, self::HEIGHT);
                imagedestroy($bg);

                return;
            }
        }

        // No/​unparseable background file: fall back to the gradient + starfield so the card still ships.
        $this->drawGradientBackground($canvas);
    }

    private function drawGradientBackground(GdImage $canvas): void
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

    private function drawCentered(GdImage $canvas, string $text, float $size, string $font, int $color, int $left, int $right, int $baselineY, ?int $scrim = null): void
    {
        $width = $this->textWidth($text, $size, $font);
        $x = max($left, $left + (int) round(($right - $left - $width) / 2));
        $this->scrimBehindText($canvas, $text, $size, $font, $x, $baselineY, $scrim);
        imagettftext($canvas, $size, 0, $x, $baselineY, $color, $font, $text);
    }

    private function drawLeft(GdImage $canvas, string $text, float $size, string $font, int $color, int $x, int $baselineY, ?int $scrim = null): void
    {
        $this->scrimBehindText($canvas, $text, $size, $font, $x, $baselineY, $scrim);
        imagettftext($canvas, $size, 0, $x, $baselineY, $color, $font, $text);
    }

    private function drawRight(GdImage $canvas, string $text, float $size, string $font, int $color, int $rightX, int $baselineY, ?int $scrim = null): void
    {
        $x = $rightX - $this->textWidth($text, $size, $font);
        $this->scrimBehindText($canvas, $text, $size, $font, $x, $baselineY, $scrim);
        imagettftext($canvas, $size, 0, $x, $baselineY, $color, $font, $text);
    }

    /**
     * Pixel rect (x1,y1 = top-left, x2,y2 = bottom-right) that $text occupies
     * when drawn at pen position ($x, $baselineY) with imagettftext at angle 0.
     *
     * @return array{int, int, int, int}
     */
    private function textRect(string $text, float $size, string $font, int $x, int $baselineY): array
    {
        $box = imagettfbbox($size, 0, $font, $text);
        if ($box === false || $text === '') {
            return [$x, $baselineY, $x, $baselineY];
        }

        return [
            $x + min($box[0], $box[2], $box[4], $box[6]),
            $baselineY + min($box[1], $box[3], $box[5], $box[7]),
            $x + max($box[0], $box[2], $box[4], $box[6]),
            $baselineY + max($box[1], $box[3], $box[5], $box[7]),
        ];
    }

    private function paintScrim(GdImage $canvas, int $x1, int $y1, int $x2, int $y2, ?int $color): void
    {
        if ($color === null || $x2 <= $x1 || $y2 <= $y1) {
            return;
        }
        // Blend only this fill so the translucent scrim mixes with the art below, then
        // restore replace-mode (the default) so the background/portrait copies and text stay crisp.
        $previous = imagealphablending($canvas, true);
        imagefilledrectangle($canvas, $x1, $y1, $x2, $y2, $color);
        imagealphablending($canvas, $previous);
    }

    private function scrimBehindText(GdImage $canvas, string $text, float $size, string $font, int $x, int $baselineY, ?int $color): void
    {
        if ($color === null) {
            return;
        }
        [$x1, $y1, $x2, $y2] = $this->textRect($text, $size, $font, $x, $baselineY);
        $this->paintScrim($canvas, $x1 - self::SCRIM_PAD_X, $y1 - self::SCRIM_PAD_Y, $x2 + self::SCRIM_PAD_X, $y2 + self::SCRIM_PAD_Y, $color);
    }

    /**
     * Draws one ability block: a label + name header line (accent), then the
     * effect (muted, indented, wrapped up to 2 lines). When $quoteEffect is true
     * the effect is wrapped in straight ASCII quotes — used to present the special
     * ability as a direct quote. Returns the next baseline Y so blocks can flow.
     */
    private function drawAbility(GdImage $canvas, string $label, string $name, string $effect, string $headerFont, string $bodyFont, int $headerColor, int $bodyColor, int $left, int $right, int $width, int $y, float $headerSize, float $bodySize, int $bodyLineHeight, bool $quoteEffect, ?int $scrim = null): int
    {
        if (trim($name) !== '') {
            $this->drawLeft($canvas, $label . trim($name), $headerSize, $headerFont, $headerColor, $left, $y, $scrim);
        }
        $y += (int) ($headerSize + 8);
        if (trim($effect) !== '') {
            $text = $quoteEffect ? '"' . trim($effect) . '"' : trim($effect);
            $bodyLeft = $left + 14;
            $used = min(2, count($this->wrap($text, $bodySize, $bodyFont, $width - 14)));
            $this->drawWrapped($canvas, $text, $bodySize, $bodyFont, $bodyColor, $bodyLeft, $right, $y, $bodyLineHeight, 2, $scrim);
            $y += $used * $bodyLineHeight;
        }

        return $y;
    }

    /**
     * @param string[] $out
     */
    private function drawWrapped(GdImage $canvas, string $text, float $size, string $font, int $color, int $left, int $right, int $baselineY, int $lineHeight, int $maxLines, ?int $scrim = null): void
    {
        $lines = $this->wrap($text, $size, $font, $right - $left);
        if ($scrim !== null) {
            // One scrim covering the whole wrapped block, so multi-line bodies read as a single panel.
            $x1 = PHP_INT_MAX;
            $y1 = PHP_INT_MAX;
            $x2 = -PHP_INT_MAX;
            $y2 = -PHP_INT_MAX;
            $y = $baselineY;
            foreach ($lines as $i => $line) {
                if ($i >= $maxLines) {
                    break;
                }
                [$lx1, $ly1, $lx2, $ly2] = $this->textRect($line, $size, $font, $left, $y);
                $x1 = min($x1, $lx1);
                $y1 = min($y1, $ly1);
                $x2 = max($x2, $lx2);
                $y2 = max($y2, $ly2);
                $y += $lineHeight;
            }
            $this->paintScrim($canvas, $x1 - self::SCRIM_PAD_X, $y1 - self::SCRIM_PAD_Y, $x2 + self::SCRIM_PAD_X, $y2 + self::SCRIM_PAD_Y, $scrim);
        }
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

    /**
     * Resolves a rarity to its 880x1180 background PNG path (no existence check).
     */
    private static function background(string $rarity): string
    {
        return __DIR__ . '/backgrounds/' . $rarity . '.png';
    }
}
