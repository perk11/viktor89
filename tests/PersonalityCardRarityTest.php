<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\PersonalityCard\PersonalityCardRarity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PersonalityCardRarity::class)]
class PersonalityCardRarityTest extends TestCase
{
    public static function powerTiers(): array
    {
        return [
            'bottom of common'   => [0, PersonalityCardRarity::COMMON],
            'mid common'         => [15, PersonalityCardRarity::COMMON],
            'first rare'         => [20, PersonalityCardRarity::RARE],
            'first epic'         => [28, PersonalityCardRarity::EPIC],
            'first legendary'    => [36, PersonalityCardRarity::LEGENDARY],
            'first mythic'       => [44, PersonalityCardRarity::MYTHIC],
            'max power'          => [50, PersonalityCardRarity::MYTHIC],
            'negative clamps'    => [-10, PersonalityCardRarity::COMMON],
        ];
    }

    #[DataProvider('powerTiers')]
    public function testFromPowerMapsToExpectedTier(int $power, string $expected): void
    {
        $this->assertSame($expected, PersonalityCardRarity::fromPower($power));
    }

    public function testEachTierHasDistinctColour(): void
    {
        $seen = [];
        foreach (PersonalityCardRarity::all() as $rarity) {
            $color = PersonalityCardRarity::color($rarity);
            $this->assertCount(3, $color);
            $key = implode(',', $color);
            $this->assertArrayNotHasKey($key, $seen, "Duplicate colour for $rarity");
            $seen[$key] = true;
        }
    }

    public function testStarsIncreaseWithRarity(): void
    {
        $previous = 0;
        foreach (PersonalityCardRarity::all() as $rarity) {
            $stars = PersonalityCardRarity::stars($rarity);
            $this->assertGreaterThan($previous, $stars, "$rarity should have more stars than the previous tier");
            $previous = $stars;
        }
        $this->assertSame(5, PersonalityCardRarity::stars(PersonalityCardRarity::MYTHIC));
    }

    public function testUnknownRarityDegradesToCommon(): void
    {
        $this->assertSame(PersonalityCardRarity::COMMON, PersonalityCardRarity::fromPower(1));
        $this->assertSame('Common', PersonalityCardRarity::label('nope'));
        $this->assertSame(1, PersonalityCardRarity::stars('nope'));
        $this->assertSame([176, 184, 196], PersonalityCardRarity::color('nope'));
    }
}
