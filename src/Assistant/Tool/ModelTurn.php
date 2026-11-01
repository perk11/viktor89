<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool;

/** One model response inside a tool-call loop. */
final readonly class ModelTurn
{
    /**
     * @param list<ToolCall> $toolCalls the tool calls the model requested, without results
     * @param ?string $stopNotice shown to the user when the model stopped early (refusal, output limit); ends the loop
     * @param mixed $replay API-specific data the assistant needs to replay this turn after its tool calls
     */
    public function __construct(
        public string $text,
        public string $reasoning = '',
        public array $toolCalls = [],
        public bool $abortedForRepetition = false,
        public ?string $stopNotice = null,
        public mixed $replay = null,
    ) {
    }
}
