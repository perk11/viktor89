<?php

declare(strict_types=1);

namespace Perk11\Viktor89\PersonalityCard;

/**
 * Rarity tiers for personality cards, derived deterministically from a card's
 * total power (the sum of its 0..10 stats, so 0..50). Shared by the processor
 * (which derives the tier) and the renderer (which paints each tier's colour).
 */
final class PersonalityCardRarity
{
    public const COMMON = 'common';
    public const RARE = 'rare';
    public const EPIC = 'epic';
    public const LEGENDARY = 'legendary';
    public const MYTHIC = 'mythic';

    /** power floor (inclusive) => rarity, scanned high to low */
    private const FLOORS = [
        44 => self::MYTHIC,
        36 => self::LEGENDARY,
        28 => self::EPIC,
        20 => self::RARE,
        0  => self::COMMON,
    ];

    /** rarity => [r, g, b] */
    private const COLORS = [
        self::COMMON    => [176, 184, 196],
        self::RARE      => [72, 136, 230],
        self::EPIC      => [176, 96, 232],
        self::LEGENDARY => [240, 196, 74],
        self::MYTHIC    => [240, 86, 150],
    ];

    private const STARS = [
        self::COMMON    => 1,
        self::RARE      => 2,
        self::EPIC      => 3,
        self::LEGENDARY => 4,
        self::MYTHIC    => 5,
    ];

    private const LABELS = [
        self::COMMON    => 'Common',
        self::RARE      => 'Rare',
        self::EPIC      => 'Epic',
        self::LEGENDARY => 'Legendary',
        self::MYTHIC    => 'Mythic',
    ];

    public static function fromPower(int $power): string
    {
        $power = max(0, $power);
        foreach (self::FLOORS as $floor => $rarity) {
            if ($power >= $floor) {
                return $rarity;
            }
        }

        return self::COMMON;
    }

    /** @return array{int, int, int} */
    public static function color(string $rarity): array
    {
        return self::COLORS[$rarity] ?? self::COLORS[self::COMMON];
    }

    public static function stars(string $rarity): int
    {
        return self::STARS[$rarity] ?? 1;
    }

    public static function label(string $rarity): string
    {
        return self::LABELS[$rarity] ?? self::LABELS[self::COMMON];
    }

    /** @return string[] */
    public static function all(): array
    {
        return [self::COMMON, self::RARE, self::EPIC, self::LEGENDARY, self::MYTHIC];
    }
}
