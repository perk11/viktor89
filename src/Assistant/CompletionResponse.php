<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant;

use Perk11\Viktor89\Assistant\Tool\ToolCall;

final readonly class CompletionResponse
{
    /**
     * @param ToolCall[] $toolCalls
     * @param string|null $displayContent The text to show in Telegram. Falls back to
     *        $content when null. Differs from $content only by display-only
     *        segments (e.g. the "Executing tool" notice) that must never be
     *        persisted or replayed to the LLM.
     */
    public function __construct(
        public string $content,
        public array $toolCalls = [],
        public ?string $reasoning = null,
        public ?string $displayContent = null,
    ) {
    }

    /**
     * The text to display in Telegram: the explicit display content when set,
     * otherwise the (clean) content.
     */
    public function getDisplayContent(): string
    {
        return $this->displayContent ?? $this->content;
    }
}
