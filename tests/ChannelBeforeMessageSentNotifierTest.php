<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Amp\Cancellation;
use Amp\Future;
use Amp\Sync\Channel;
use Perk11\Viktor89\IPC\AckMessage;
use Perk11\Viktor89\IPC\ChannelBeforeMessageSentNotifier;
use Perk11\Viktor89\IPC\MessageAboutToBeSentMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

#[CoversClass(ChannelBeforeMessageSentNotifier::class)]
class ChannelBeforeMessageSentNotifierTest extends TestCase
{
    public function testConcurrentNotificationsEachWaitForTheirOwnAck(): void
    {
        $channel = new class implements Channel {
            /** @var list<string> */
            public array $events = [];

            public function send(mixed $data): void
            {
                \assert($data instanceof MessageAboutToBeSentMessage);
                $this->events[] = "sent $data->chatId";
            }

            public function receive(?Cancellation $cancellation = null): mixed
            {
                delay(0.05);
                $this->events[] = 'acked';

                return new AckMessage(1);
            }

            public function close(): void
            {
            }

            public function isClosed(): bool
            {
                return false;
            }

            public function onClose(\Closure $onClose): void
            {
            }
        };
        $notifier = new ChannelBeforeMessageSentNotifier($channel, 1);

        Future\await([
            async(static fn () => $notifier->notify(10)),
            async(static fn () => $notifier->notify(20)),
        ]);

        $this->assertSame(['sent 10', 'acked', 'sent 20', 'acked'], $channel->events);
    }
}
