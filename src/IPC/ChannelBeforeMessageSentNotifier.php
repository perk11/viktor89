<?php

namespace Perk11\Viktor89\IPC;

use Amp\Sync\Channel;

/**
 * BeforeMessageSentNotifier backed by a worker's IPC channel: sends a
 * MessageAboutToBeSentMessage and blocks on the AckMessage that confirms the
 * main process has stopped drafts/typing for the chat.
 */
class ChannelBeforeMessageSentNotifier implements BeforeMessageSentNotifier
{
    public function __construct(
        private readonly Channel $channel,
        private readonly int $workerId,
    ) {
    }

    public function notify(int $chatId): void
    {
        $this->channel->send(new MessageAboutToBeSentMessage($this->workerId, $chatId));
        $ack = $this->channel->receive();
        if (!$ack instanceof AckMessage) {
            throw new \LogicException('Expected AckMessage, got ' . get_class($ack));
        }
    }
}
