<?php

declare(strict_types=1);

namespace Perk11\Viktor89\PersonalityCard;

/**
 * A generated collectible stat card for one chat member. Carries the LLM-derived
 * stats/flavour plus the rarity/power derived from those stats, ready to be drawn
 * by PersonalityCardRenderer.
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
        public readonly string $quote,
        public readonly string $portraitPrompt,
        public readonly string $rarity,
        public readonly int $power,
        public readonly int $stars,
        public readonly string $cardNumber,
    ) {
    }
}
