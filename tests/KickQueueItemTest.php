<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\JoinQuiz\KickQueueItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KickQueueItem::class)]
class KickQueueItemTest extends TestCase
{
    public function testConstructorWithAllFields(): void
    {
        $item = new KickQueueItem(-100123, 456, 789, 999, [1, 2, 3], 1234567890);

        $this->assertSame(-100123, $item->chatId);
        $this->assertSame(456, $item->userId);
        $this->assertSame(789, $item->pollId);
        $this->assertSame(999, $item->joinMessageId);
        $this->assertSame([1, 2, 3], $item->messagesToDelete);
        $this->assertSame(1234567890, $item->kickTime);
    }

    public function testConstructorWithNullKickTime(): void
    {
        $item = new KickQueueItem(-100123, 456, 789, 999, [], null);

        $this->assertNull($item->kickTime);
        $this->assertSame([], $item->messagesToDelete);
    }

    public function testFromSqliteAssoc(): void
    {
        $result = [
            'chat_id' => -100456,
            'user_id' => 789,
            'poll_id' => 101,
            'join_message_id' => 202,
            'messages_to_delete' => '10,20,30',
            'kick_time' => 1609459200,
        ];

        $item = KickQueueItem::fromSqliteAssoc($result);

        $this->assertSame(-100456, $item->chatId);
        $this->assertSame(789, $item->userId);
        $this->assertSame(101, $item->pollId);
        $this->assertSame(202, $item->joinMessageId);
        $this->assertSame(['10', '20', '30'], $item->messagesToDelete);
        $this->assertSame(1609459200, $item->kickTime);
    }

    public function testFromSqliteAssocWithEmptyMessagesToDelete(): void
    {
        $result = [
            'chat_id' => -100123,
            'user_id' => 456,
            'poll_id' => 789,
            'join_message_id' => 999,
            'messages_to_delete' => '',
            'kick_time' => null,
        ];

        $item = KickQueueItem::fromSqliteAssoc($result);

        $this->assertSame([''], $item->messagesToDelete);
        $this->assertNull($item->kickTime);
    }

    public function testFromSqliteAssocWithSingleMessageToDelete(): void
    {
        $result = [
            'chat_id' => -100123,
            'user_id' => 456,
            'poll_id' => 789,
            'join_message_id' => 999,
            'messages_to_delete' => '42',
            'kick_time' => 1234567890,
        ];

        $item = KickQueueItem::fromSqliteAssoc($result);

        $this->assertSame(['42'], $item->messagesToDelete);
    }
}
