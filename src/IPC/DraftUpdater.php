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
 *  - sends the draft to Telegram immediately whenever new content arrives,
 *  - refreshes it on a timer so it does not disappear during pauses, and
 *  - throttles sends to at most $maxSendsPerWindow per $sendWindowSeconds per
 *    chat, deferring (and coalescing) any excess so only the latest content is
 *    delivered once a slot frees up.
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

    /** @var array<int, list<float>> chatId => send timestamps still inside the throttle window */
    private array $sendTimestamps = [];

    /** @var array<int, string> chatId => EventLoop delay ID for a deferred (throttled) send */
    private array $pendingFlushTimers = [];

    public function __construct(
        private readonly FinalMessageTracker $finalMessageTracker,
        private readonly float $refreshIntervalSeconds = 10,
        private readonly int $maxSendsPerWindow = 3,
        private readonly float $sendWindowSeconds = 1.0,
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

        // Edits target a message that does not disappear, so unlike drafts they
        // need no periodic refresh timer.
        if ($draft->editMessageId === null && !isset($this->draftTimers[$draft->chatId])) {
            $this->startDraftTimer($draft->chatId);
        }

        $this->requestSend($draft->chatId);
    }

    public function removeDraft(int $workerId): void
    {
        $draft = $this->workerDrafts[$workerId] ?? null;
        if ($draft === null) {
            return;
        }

        unset($this->workerDrafts[$workerId]);

        if ($this->workerIdsForChat($draft->chatId) === []) {
            $this->cleanupChat($draft->chatId);
        }
    }

    /**
     * Flush the worker's latest streamed draft once, then drop it.
     *
     * Called on TaskCompletedMessage. When the worker sent a final message,
     * the MessageAboutToBeSentMessage→Ack handshake already ran removeDraft()
     * before the worker's own send/edit, so workerDrafts[$workerId] is empty
     * and this is a no-op.
     *
     * It only fires when the worker finishes without sending a final message
     * (abort, reaction-only, exception): no handshake ran, so removeDraft()
     * was never called. Flushing first matters for the edit-stream path, whose
     * latest content can live only in a deferred throttled flush — edits get no
     * refresh timer — and a bare removeDraft() would cancel that timer and
     * leave the message frozen at stale content.
     */
    public function deliverAndRemoveDraft(int $workerId): void
    {
        if (!isset($this->workerDrafts[$workerId])) {
            return;
        }

        $this->sendDraftForWorker($workerId);
        $this->removeDraft($workerId);
    }

    private function startDraftTimer(int $chatId): void
    {
        $this->draftTimers[$chatId] = EventLoop::repeat(
            $this->refreshIntervalSeconds,
            fn () => $this->requestSend($chatId),
        );
    }

    private function cleanupChat(int $chatId): void
    {
        if (isset($this->draftTimers[$chatId])) {
            EventLoop::cancel($this->draftTimers[$chatId]);
            unset($this->draftTimers[$chatId]);
        }

        if (isset($this->pendingFlushTimers[$chatId])) {
            EventLoop::cancel($this->pendingFlushTimers[$chatId]);
            unset($this->pendingFlushTimers[$chatId]);
        }

        unset($this->sendTimestamps[$chatId], $this->pausedUntil[$chatId]);
    }

    /**
     * Entry point for every desire to push a draft to Telegram: the streaming
     * worker, the periodic refresh timer, and a deferred (throttled) flush all
     * funnel through here.
     *
     * Sends right away while the chat is below its rate limit; otherwise
     * schedules a single deferred flush (see scheduleFlush) that will carry the
     * latest content once a slot frees up. If such a flush is already pending
     * this is a no-op, so rapid updates collapse into the one deferred send.
     */
    private function requestSend(int $chatId): void
    {
        if (isset($this->pendingFlushTimers[$chatId])) {
            return;
        }

        $workerIds = $this->workerIdsForChat($chatId);
        if ($workerIds === []) {
            return;
        }

        $now = microtime(true);

        if (($this->pausedUntil[$chatId] ?? 0.0) > $now) {
            return;
        }

        $cutoff = $now - $this->sendWindowSeconds;
        foreach ($workerIds as $workerId) {
            if ($this->finalMessageTracker->isFinalMessageBeingSentByWorker($workerId)) {
                $this->removeDraft($workerId);
                continue;
            }

            $this->sendTimestamps[$chatId] = array_values(array_filter(
                $this->sendTimestamps[$chatId] ?? [],
                static fn(float $timestamp): bool => $timestamp > $cutoff,
            ));
            if (count($this->sendTimestamps[$chatId]) >= $this->maxSendsPerWindow) {
                $this->scheduleFlush(
                    $chatId,
                    $this->sendTimestamps[$chatId][0] + $this->sendWindowSeconds - $now,
                );

                return;
            }

            $this->sendTimestamps[$chatId][] = $now;
            $this->sendDraftForWorker($workerId);

            if (($this->pausedUntil[$chatId] ?? 0.0) > microtime(true)) {
                return;
            }
        }
    }

    private function scheduleFlush(int $chatId, float $delaySeconds): void
    {
        if (isset($this->pendingFlushTimers[$chatId])) {
            return;
        }

        $this->pendingFlushTimers[$chatId] = EventLoop::delay(
            max($delaySeconds, 0.001),
            function () use ($chatId): void {
                unset($this->pendingFlushTimers[$chatId]);
                $this->requestSend($chatId);
            },
        );
    }

    /** @return list<int> */
    private function workerIdsForChat(int $chatId): array
    {
        return array_keys(array_filter(
            $this->workerDrafts,
            static fn(DraftState $draft): bool => $draft->chatId === $chatId,
        ));
    }

    private function sendDraftForWorker(int $workerId): void
    {
        $draft = $this->workerDrafts[$workerId] ?? null;
        if ($draft === null) {
            return;
        }

        $message = new InternalMessage();
        $message->chatId = $draft->chatId;
        $message->messageText = $draft->text;
        $message->parseMode = $draft->parseMode;
        $message->messageThreadId = $draft->messageThreadId;

        if ($draft->editMessageId !== null) {
            echo date('Y-m-d H:i:s') . " Editing message {$draft->editMessageId} in {$draft->chatId} ($workerId)\n";
            $message->id = $draft->editMessageId;
            $response = $message->edit($draft->text, false);
            if (!$response->isOk()) {
                $rawData = $response->getRawData();
                $this->handleFailedSend(
                    $draft->chatId,
                    $workerId,
                    $response->getErrorCode(),
                    $response->getDescription(),
                    $rawData['parameters']['retry_after'] ?? null,
                );
            }

            return;
        }

        echo date('Y-m-d H:i:s') . " Sending draft to {$draft->chatId} ($workerId)\n";
        $message->draftId = $draft->draftId;
        $result = json_decode($message->sendAsDraft(), false);

        if (json_last_error() !== JSON_ERROR_NONE) {
            echo date('Y-m-d H:i:s') . " Failed to parse result of sending draft: " . json_last_error_msg() . "\n";
            return;
        }

        if ($result->ok === false) {
            $this->handleFailedSend(
                $draft->chatId,
                $workerId,
                $result->error_code,
                $result->description,
                $result->parameters->retry_after ?? null,
            );
        }
    }

    private function handleFailedSend(int $chatId, int $workerId, int $errorCode, string $description, ?int $retryAfter): void
    {
        if ($errorCode === 429 && $retryAfter !== null) {
            echo date('Y-m-d H:i:s') . " Got retry after {$retryAfter} in chat {$chatId} ($workerId): {$description}\n";
            $this->pausedUntil[$chatId] = microtime(true) + $retryAfter;
        } else {
            echo date('Y-m-d H:i:s') . " Failed to send in chat {$chatId} ($workerId): {$errorCode} {$description}\n";
        }
    }
}
