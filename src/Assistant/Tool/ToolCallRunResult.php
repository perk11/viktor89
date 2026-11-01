<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool;

final class ToolCallRunResult
{
    /**
     * @param ToolCall $toolCall the executed call; its result is the JSON-encoded tool result to send back to the model
     * @param list<array<string, mixed>> $imageParts images the tool returned, in the calling API's content-part format
     */
    public function __construct(
        public readonly ToolCall $toolCall,
        public readonly array $imageParts,
        public readonly bool $failed,
    ) {
    }
}
