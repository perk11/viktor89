<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Assistant\Tool;

use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\Util\Telegram\ChatAction;

/**
 * Merges the progress of the concurrently running calls of one turn into a
 * single status, so /status shows one row listing every call instead of
 * whichever call reported last.
 */
final class ConcurrentToolCallProgress
{
    /** @var array<int, string> the latest status of each call, unless it is the generic "Executing <name>" */
    private array $details = [];
    /** @var array<int, true> */
    private array $finished = [];

    /**
     * @param list<ToolCall> $toolCalls
     */
    public function __construct(
        private readonly ProgressUpdateCallback $progressUpdateCallback,
        private readonly array $toolCalls,
    ) {
    }

    public function callbackFor(int $index): ProgressUpdateCallback
    {
        return new readonly class ($this, $index) implements ProgressUpdateCallback {
            public function __construct(private ConcurrentToolCallProgress $progress, private int $index)
            {
            }

            public function __invoke(string $processor, string $status, ?ChatAction $chatAction = null): void
            {
                $this->progress->update($this->index, $processor, $status, $chatAction);
            }

            public function subscribe(callable $subscriber): void
            {
                $this->progress->subscribe($subscriber);
            }
        };
    }

    public function update(int $index, string $processor, string $status, ?ChatAction $chatAction = null): void
    {
        if ($status === "Executing {$this->toolCalls[$index]->name}") {
            unset($this->details[$index]);
        } else {
            $this->details[$index] = $status;
        }
        $this->report($chatAction);
    }

    public function finish(int $index): void
    {
        $this->finished[$index] = true;
        // Once all are done the tool loop reports its own status.
        if (count($this->finished) < count($this->toolCalls)) {
            $this->report(null);
        }
    }

    public function subscribe(callable $subscriber): void
    {
        $this->progressUpdateCallback->subscribe($subscriber);
    }

    private function report(?ChatAction $chatAction): void
    {
        $entries = [];
        foreach ($this->toolCalls as $index => $toolCall) {
            $entries[] = match (true) {
                isset($this->finished[$index]) => "✓ $toolCall->name",
                isset($this->details[$index]) => "$toolCall->name: {$this->details[$index]}",
                default => $toolCall->name,
            };
        }

        ($this->progressUpdateCallback)(
            ToolCallRunner::class,
            'Executing ' . count($this->toolCalls) . ' tool calls: ' . implode('; ', $entries),
            $chatAction,
        );
    }
}
