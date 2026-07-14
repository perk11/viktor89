<?php

declare(strict_types=1);

namespace Perk11\Viktor89\PersonalityCard;

/**
 * A generated collectible stat card for one chat member. Carries the LLM-derived
 * stats + a signature ability + a special (ultimate) ability + weakness, plus the
 * rarity/power/element derived from those stats, ready to be drawn by
 * PersonalityCardRenderer. All visible text is Russian.
 */
final class PersonalityCard
{
    /**
     * @param array<string, int> $stats stat key (see PersonalityCardProcessor::STATS) => 0..10
     */
    public function __construct(
        public readonly string $name,
        public readonly string $archetype,
        public readonly array $stats,
        public readonly string $element,
        public readonly string $ability,
        public readonly string $abilityEffect,
        public readonly string $specialAbility,
        public readonly string $specialAbilityQuote,
        public readonly string $weakness,
        public readonly string $portraitPrompt,
        public readonly string $rarity,
        public readonly int $power,
        public readonly int $stars,
        public readonly string $cardNumber,
    ) {
    }
}
