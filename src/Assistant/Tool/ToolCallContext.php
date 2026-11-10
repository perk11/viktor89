<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool;

use Perk11\Viktor89\Assistant\ResponseContentAccumulator;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;

/** Per-completion state shared by every tool call of one tool-call loop. */
final class ToolCallContext
{
    public function __construct(
        public readonly ResponseContentAccumulator $accumulator,
        public readonly ?\Closure $streamFunction,
        public readonly ?MessageChain $messageChain,
        public readonly ?ProgressUpdateCallback $progressUpdateCallback,
    ) {
    }

    public function withProgressUpdateCallback(?ProgressUpdateCallback $progressUpdateCallback): self
    {
        return new self($this->accumulator, $this->streamFunction, $this->messageChain, $progressUpdateCallback);
    }

    /**
     * Shows a notice to the Telegram user only. It never reaches the model,
     * which already sees the call via the structured tool call messages.
     */
    public function notify(string $notice): void
    {
        $this->accumulator->appendTelegramDisplayOnly($notice);
        if ($this->streamFunction !== null) {
            ($this->streamFunction)($notice);
        }
    }
}
