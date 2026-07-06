<?php

namespace Perk11\Viktor89\IPC;

use Perk11\Viktor89\InternalMessage;
use Revolt\EventLoop;

/**
 * Keeps message drafts alive in the main process.
 *
 * Drafts are sent from workers as the response is streamed, but a draft also
 * needs to be periodically refreshed (otherwise it disappears). This cannot be
 * done reliably from the worker process, because the worker is blocked while
 * waiting for the model (e.g. during "thinking"). Instead, the worker forwards
 * the latest draft content here, and this class:
 *  - sends the draft to Telegram immediately whenever new content arrives, and
 *  - refreshes it on a timer so it does not disappear during pauses.
 *
 * Drafts are never refreshed for a worker whose final message is being sent
 * (see FinalMessageTracker), so a draft should never be sent after the actual
 * message.
 */
class DraftUpdater
{
    /** @var array<int, string> */
    private array $draftTimers = [];

    /** @var array<int, DraftState> */
    private array $workerDrafts = [];

    /** @var array<int, float> chatId => microtime until which sending is paused due to rate limiting */
    private array $pausedUntil = [];

    public function __construct(
        private readonly FinalMessageTracker $finalMessageTracker,
        private readonly float $refreshIntervalSeconds = 10,
    ) {
    }

    public function updateDraft(int $workerId, DraftState $draft): void
    {
        if ($this->finalMessageTracker->isFinalMessageBeingSentByWorker($workerId)) {
            return;
        }

        $previousDraft = $this->workerDrafts[$workerId] ?? null;
        if ($previousDraft !== null && $previousDraft->chatId !== $draft->chatId) {
            $this->removeDraft($workerId);
        }

        $this->workerDrafts[$workerId] = $draft;

        if (!isset($this->draftTimers[$draft->chatId])) {
            $this->startDraftTimer($draft->chatId);
        }

        $this->sendDraftForWorker($workerId);
    }

    public function removeDraft(int $workerId): void
    {
        $draft = $this->workerDrafts[$workerId] ?? null;
        if ($draft === null) {
            return;
        }

        unset($this->workerDrafts[$workerId]);

        if (!$this->chatHasPendingDrafts($draft->chatId)) {
            $this->stopDraftTimer($draft->chatId);
        }
    }

    private function startDraftTimer(int $chatId): void
    {
        $this->draftTimers[$chatId] = EventLoop::repeat(
            $this->refreshIntervalSeconds,
            fn () => $this->refreshDraftsForChat($chatId)
        );
    }

    private function stopDraftTimer(int $chatId): void
    {
        if (!isset($this->draftTimers[$chatId])) {
            return;
        }

        EventLoop::cancel($this->draftTimers[$chatId]);
        unset($this->draftTimers[$chatId], $this->pausedUntil[$chatId]);
    }

    private function chatHasPendingDrafts(int $chatId): bool
    {
        foreach ($this->workerDrafts as $draft) {
            if ($draft->chatId === $chatId) {
                return true;
            }
        }

        return false;
    }

    private function refreshDraftsForChat(int $chatId): void
    {
        $workerIds = array_keys(
            array_filter(
                $this->workerDrafts,
                static fn(DraftState $draft): bool => $draft->chatId === $chatId,
            ),
        );

        foreach ($workerIds as $workerId) {
            if ($this->finalMessageTracker->isFinalMessageBeingSentByWorker($workerId)) {
                $this->removeDraft($workerId);
                continue;
            }

            $this->sendDraftForWorker($workerId);
        }
    }

    private function sendDraftForWorker(int $workerId): void
    {
        $draft = $this->workerDrafts[$workerId] ?? null;
        if ($draft === null) {
            return;
        }

        if (($this->pausedUntil[$draft->chatId] ?? 0.0) > microtime(true)) {
            return;
        }

        echo date('Y-m-d H:i:s') . " Sending draft to {$draft->chatId} ($workerId)\n";

        $message = new InternalMessage();
        $message->chatId = $draft->chatId;
        $message->draftId = $draft->draftId;
        $message->messageText = $draft->text;
        $message->parseMode = $draft->parseMode;
        $message->messageThreadId = $draft->messageThreadId;

        $sendAsDraftResult = $message->sendAsDraft();
        $sendAsDraftResultObject = json_decode($sendAsDraftResult, false);

        if (json_last_error() !== JSON_ERROR_NONE) {
            echo date('Y-m-d H:i:s') . " Failed to parse result of sending draft: " . json_last_error_msg() . "\n";
            return;
        }

        if ($sendAsDraftResultObject->ok === false) {
            $retryAfter = $sendAsDraftResultObject->parameters->retry_after ?? null;
            if ($sendAsDraftResultObject->error_code === 429 && $retryAfter !== null) {
                echo date('Y-m-d H:i:s') . " Got retry after {$retryAfter} when sending draft to {$draft->chatId} ($workerId): {$sendAsDraftResultObject->description}\n";
                $this->pausedUntil[$draft->chatId] = microtime(true) + $retryAfter;
            } else {
                echo date('Y-m-d H:i:s') . " Failed to send draft to {$draft->chatId} ($workerId): {$sendAsDraftResultObject->error_code} {$sendAsDraftResultObject->description}\n";
            }
        }
    }
}
