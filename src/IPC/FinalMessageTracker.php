<?php

namespace Perk11\Viktor89\IPC;

/**
 * Tracks workers whose final response message is currently being sent.
 *
 * Both ChatActionUpdater and DraftUpdater consult this before sending a typing
 * notification or refreshing a draft, so that such notifications are suppressed
 * once a worker has signalled that its final message is about to be sent.
 */
class FinalMessageTracker
{
    /** @var array<int, true> */
    private array $finalizingWorkers = [];

    public function markWorkerFinalizing(int $workerId): void
    {
        $this->finalizingWorkers[$workerId] = true;
    }

    public function isFinalMessageBeingSentByWorker(int $workerId): bool
    {
        return isset($this->finalizingWorkers[$workerId]);
    }

    public function clearWorker(int $workerId): void
    {
        unset($this->finalizingWorkers[$workerId]);
    }
}
