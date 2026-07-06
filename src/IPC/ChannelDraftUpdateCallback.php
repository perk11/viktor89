<?php

namespace Perk11\Viktor89\IPC;

use Amp\Sync\Channel;

/**
 * Forwards draft updates from a worker to the main process over its IPC channel
 * (as DraftUpdateMessage), so the main process can send the draft to Telegram
 * and keep it alive on a timer.
 */
class ChannelDraftUpdateCallback implements DraftUpdateCallback
{
    public function __construct(
        private readonly Channel $channel,
        private readonly int $workerId,
    ) {
    }

    public function updateDraft(DraftState $draft): void
    {
        $this->channel->send(new DraftUpdateMessage($this->workerId, $draft));
    }
}
