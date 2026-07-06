<?php

namespace Perk11\Viktor89\IPC;

class DraftUpdateMessage extends ChannelMessage
{
    public function __construct(
        public readonly int $workerId,
        public readonly DraftState $draft,
    ) {
    }
}
