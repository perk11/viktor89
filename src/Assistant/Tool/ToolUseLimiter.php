<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool;

/**
 * Safety backstops for one completion's tool-call loop. A model that keeps
 * calling the SAME tool over and over (e.g. re-generating an image it cannot
 * visually verify) is stopped after MAX_CONSECUTIVE_SAME_TOOL_USES calls in a
 * row; calling any other tool resets that counter. MAX_TOTAL_TOOL_USES is a
 * hard ceiling on the whole completion regardless of which tools are used.
 */
final class ToolUseLimiter
{
    public const int MAX_CONSECUTIVE_SAME_TOOL_USES = 20;
    public const int MAX_TOTAL_TOOL_USES = 150;

    private int $totalToolUses = 0;
    private ?string $lastToolName = null;
    private int $consecutiveSameToolUses = 0;

    public function record(string $toolName): void
    {
        $this->totalToolUses++;
        if ($toolName === $this->lastToolName) {
            $this->consecutiveSameToolUses++;
        } else {
            $this->lastToolName = $toolName;
            $this->consecutiveSameToolUses = 1;
        }
    }

    public function limitReachedReason(): ?string
    {
        if ($this->consecutiveSameToolUses >= self::MAX_CONSECUTIVE_SAME_TOOL_USES) {
            return "the same tool (`$this->lastToolName`) was called "
                . self::MAX_CONSECUTIVE_SAME_TOOL_USES
                . ' times in a row';
        }
        if ($this->totalToolUses >= self::MAX_TOTAL_TOOL_USES) {
            return 'the total limit of ' . self::MAX_TOTAL_TOOL_USES . ' tool calls was reached';
        }

        return null;
    }
}
