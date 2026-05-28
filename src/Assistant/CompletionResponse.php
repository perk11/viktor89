<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\Assistant\Tool\ToolCall;

final readonly class CompletionResponse
{
    /**
     * @param ToolCall[] $toolCalls
     */
    public function __construct(
        public string $content,
        public array $toolCalls = [],
    ) {
    }
}
