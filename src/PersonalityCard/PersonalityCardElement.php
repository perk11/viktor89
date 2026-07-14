<?php

declare(strict_types=1);

namespace Perk11\Viktor89\PersonalityCard;

/**
 * Elemental affinity (стихия) for a personality card, derived deterministically
 * from the bearer's single highest stat: the dominant trait picks the element,
 * so the affinity is always a truthful reflection of the graded stats (never an
 * LLM free-form guess that could be invalid). Ties resolve in stat-definition
 * order (wit first). Each element carries a Russian label (drawn on the card)
 * and a short English visual hint that tints the generated portrait so the
 * artwork matches the card's theme.
 *
 * No emoji glyphs are used: the card is rendered with DejaVu fonts, which do not
 * ship colour-emoji glyphs, so an emoji would draw as a missing-glyph box.
 */
final class PersonalityCardElement
{
    public const WIND = 'wind';
    public const AETHER = 'aether';
    public const ARCANE = 'arcane';
    public const FIRE = 'fire';

    /** stat key => element key, in stat-priority order (ties win the earlier entry). */
    private const STAT_ELEMENT = [
        'wit'    => self::WIND,
        'chaos'  => self::AETHER,
        'wisdom' => self::ARCANE,
        'menace' => self::FIRE,
    ];

    private const LABELS = [
        self::WIND   => 'Воздух',
        self::AETHER => 'Эфир',
        self::ARCANE => 'Магия',
        self::FIRE   => 'Огонь',
    ];

    private const PORTRAIT_HINTS = [
        self::WIND   => 'swift dynamic motion, gusts of wind, streamlined silhouette',
        self::AETHER => 'swirling prismatic aether energy, reality-bending rifts',
        self::ARCANE => 'glowing runes and arcane sigils, deep mystic glow',
        self::FIRE   => 'fiery embers, molten warm glow, drifting sparks',
    ];

    /**
     * @param array<string, int> $stats
     */
    public static function fromStats(array $stats): string
    {
        $best = null;
        $bestValue = -1;
        foreach (self::STAT_ELEMENT as $stat => $element) {
            $value = isset($stats[$stat]) ? (int) $stats[$stat] : 0;
            if ($value > $bestValue) {
                $bestValue = $value;
                $best = $element;
            }
        }

        return $best ?? self::WIND;
    }

    public static function label(string $element): string
    {
        return self::LABELS[$element] ?? self::LABELS[self::WIND];
    }

    public static function portraitHint(string $element): string
    {
        return self::PORTRAIT_HINTS[$element] ?? self::PORTRAIT_HINTS[self::WIND];
    }

    /** @return string[] */
    public static function all(): array
    {
        return [self::WIND, self::AETHER, self::ARCANE, self::FIRE];
    }
}
