<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\PersonalityCard\PersonalityCardElement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PersonalityCardElement::class)]
class PersonalityCardElementTest extends TestCase
{
    public static function dominantStatElements(): array
    {
        return [
            'wit dominates'    => [['wit' => 9, 'chaos' => 2, 'wisdom' => 2, 'menace' => 1], PersonalityCardElement::WIND],
            'chaos dominates'  => [['wit' => 1, 'chaos' => 9, 'wisdom' => 0, 'menace' => 3], PersonalityCardElement::AETHER],
            'wisdom dominates' => [['wit' => 2, 'chaos' => 1, 'wisdom' => 9, 'menace' => 1], PersonalityCardElement::ARCANE],
            'menace dominates' => [['wit' => 1, 'chaos' => 3, 'wisdom' => 0, 'menace' => 9], PersonalityCardElement::FIRE],
        ];
    }

    #[DataProvider('dominantStatElements')]
    public function testFromStatsPicksElementOfHighestStat(array $stats, string $expected): void
    {
        $this->assertSame($expected, PersonalityCardElement::fromStats($stats));
    }

    public function testTiesResolveInStatDefinitionOrder(): void
    {
        // Every stat tied at 5 — wit is first in the stat->element map, so Wind wins.
        $stats = ['wit' => 5, 'chaos' => 5, 'wisdom' => 5, 'menace' => 5];
        $this->assertSame(PersonalityCardElement::WIND, PersonalityCardElement::fromStats($stats));
    }

    public function testMissingStatsAreTreatedAsZero(): void
    {
        $this->assertSame(PersonalityCardElement::FIRE, PersonalityCardElement::fromStats(['menace' => 7]));
    }

    public function testEachElementHasLabelAndPortraitHint(): void
    {
        foreach (PersonalityCardElement::all() as $element) {
            $this->assertNotSame('', PersonalityCardElement::label($element));
            $this->assertNotSame('', PersonalityCardElement::portraitHint($element));
        }
    }

    public function testUnknownElementDegradesToWind(): void
    {
        $this->assertSame(PersonalityCardElement::WIND, PersonalityCardElement::fromStats([]));
        $this->assertSame('Воздух', PersonalityCardElement::label('nope'));
        $this->assertNotEmpty(PersonalityCardElement::portraitHint('nope'));
    }
}
