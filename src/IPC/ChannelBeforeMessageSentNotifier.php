<?php

namespace Perk11\Viktor89\IPC;

use Amp\Sync\Channel;
use Amp\Sync\LocalMutex;

use function Amp\Sync\synchronized;

/**
 * BeforeMessageSentNotifier backed by a worker's IPC channel: sends a
 * MessageAboutToBeSentMessage and blocks on the AckMessage that confirms the
 * main process has stopped drafts/typing for the chat.
 *
 * Concurrent tool calls may send messages at the same time, so each
 * send/ack pair is taken under a lock.
 */
class ChannelBeforeMessageSentNotifier implements BeforeMessageSentNotifier
{
    private readonly LocalMutex $mutex;

    public function __construct(
        private readonly Channel $channel,
        private readonly int $workerId,
    ) {
        $this->mutex = new LocalMutex();
    }

    public function notify(int $chatId): void
    {
        synchronized($this->mutex, function () use ($chatId): void {
            $this->channel->send(new MessageAboutToBeSentMessage($this->workerId, $chatId));
            $ack = $this->channel->receive();
            if (!$ack instanceof AckMessage) {
                throw new \LogicException('Expected AckMessage, got ' . get_class($ack));
            }
        });
    }
}
