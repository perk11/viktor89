<?php

namespace Perk11\Viktor89\IPC;

use Perk11\Viktor89\InternalMessage;

class DraftUpdateMessage extends ChannelMessage
{
    public function __construct(
        public readonly int $workerId,
        public readonly InternalMessage $draftMessage,
    ) {
    }
}
