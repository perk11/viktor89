<?php

namespace Perk11\Viktor89\IPC;

use Amp\Sync\Channel;
use Perk11\Viktor89\InternalMessage;

class ChannelDraftUpdatePublisher implements DraftUpdatePublisher
{
    public function __construct(private readonly Channel $channel, private readonly int $workerId)
    {
    }

    public function updateDraft(InternalMessage $draftMessage): void
    {
        $this->channel->send(new DraftUpdateMessage($this->workerId, clone $draftMessage));
    }

    public function clearDraft(): void
    {
        $this->channel->send(new DraftCompletedMessage($this->workerId));
    }
}
