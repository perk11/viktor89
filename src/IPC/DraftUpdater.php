<?php

namespace Perk11\Viktor89\IPC;

use Perk11\Viktor89\InternalMessage;
use Revolt\EventLoop;

class DraftUpdater
{
    private const float DRAFT_REFRESH_SECONDS = 25;
    private const int MAX_RETRY_AFTER_SECONDS = 120;

    /** @var array<int, InternalMessage> */
    private array $draftMessages = [];

    /** @var array<int, string> */
    private array $draftTimers = [];

    public function updateDraft(int $workerId, InternalMessage $draftMessage): void
    {
        $this->cancelRefresh($workerId);
        $trackedDraftMessage = clone $draftMessage;
        $existingDraftMessage = $this->draftMessages[$workerId] ?? null;
        if ($existingDraftMessage instanceof InternalMessage && $existingDraftMessage->draftId !== null) {
            $trackedDraftMessage->draftId = $existingDraftMessage->draftId;
        }

        $this->draftMessages[$workerId] = $trackedDraftMessage;
        $this->sendDraft($workerId);
    }

    public function removeDraft(int $workerId): void
    {
        $this->cancelRefresh($workerId);
        unset($this->draftMessages[$workerId]);
    }

    private function sendDraft(int $workerId): void
    {
        $draftMessage = $this->draftMessages[$workerId] ?? null;
        if ($draftMessage === null) {
            return;
        }

        $sendAsDraftResult = $draftMessage->sendAsDraft();
        $sendAsDraftResultObject = json_decode($sendAsDraftResult, false);

        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "Failed to parse result of sending message as draft: " . json_last_error_msg() . "\n";
            $this->removeDraft($workerId);
            return;
        }

        if ($sendAsDraftResultObject->ok === false) {
            if ($this->scheduleRateLimitRetry($workerId, $sendAsDraftResultObject)) {
                return;
            }

            var_dump($sendAsDraftResultObject);
            echo "Failed to send message as draft: {$sendAsDraftResultObject->error_code} {$sendAsDraftResultObject->description}\n";
            $this->removeDraft($workerId);
            return;
        }

        $this->scheduleRefresh($workerId);
    }

    private function scheduleRefresh(int $workerId): void
    {
        $this->scheduleRefreshAfterDelay($workerId, self::DRAFT_REFRESH_SECONDS);
    }

    private function scheduleRefreshAfterDelay(int $workerId, int|float $delaySeconds): void
    {
        $this->draftTimers[$workerId] = EventLoop::delay(
            $delaySeconds,
            function () use ($workerId) {
                unset($this->draftTimers[$workerId]);
                $draftMessage = $this->draftMessages[$workerId] ?? null;
                if ($draftMessage === null) {
                    return;
                }
                echo date('Y-m-d H:i:s') ." Refreshing draft for worker {$workerId} in chat {$draftMessage->chatId}\n";
                $this->sendDraft($workerId);
            }
        );
    }

    private function scheduleRateLimitRetry(int $workerId, object $sendAsDraftResultObject): bool
    {
        $retryAfter = $sendAsDraftResultObject->parameters->retry_after ?? null;
        if (($sendAsDraftResultObject->error_code ?? null) !== 429 || !is_numeric($retryAfter)) {
            return false;
        }

        $retryDelay = min((float) $retryAfter, self::MAX_RETRY_AFTER_SECONDS);
        echo "Got retry after {$retryAfter} when sending draft. Retrying in {$retryDelay} seconds.\n";
        $this->scheduleRefreshAfterDelay($workerId, $retryDelay);

        return true;
    }

    private function cancelRefresh(int $workerId): void
    {
        if (!isset($this->draftTimers[$workerId])) {
            return;
        }

        EventLoop::cancel($this->draftTimers[$workerId]);
        unset($this->draftTimers[$workerId]);
    }
}
