<?php

namespace Perk11\Viktor89\IPC;

/**
 * Sent by a worker immediately before it sends the final response message.
 * The main process must stop sending typing notifications / drafts for the
 * worker's chat before acknowledging, so that no typing or draft can appear
 * after the actual message.
 */
class MessageAboutToBeSentMessage extends ChannelMessage
{
    public function __construct(
        public readonly int $workerId,
        public readonly int $chatId,
    ) {
    }
}
