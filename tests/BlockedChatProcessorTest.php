<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\BlockedChatProcessor;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BlockedChatProcessor::class)]
class BlockedChatProcessorTest extends TestCase
{
    private function createMockCallback(): \Perk11\Viktor89\IPC\ProgressUpdateCallback
    {
        return $this->createMock(\Perk11\Viktor89\IPC\ProgressUpdateCallback::class);
    }

    public function testBlocksMessageFromBlockedChat(): void
    {
        $processor = new BlockedChatProcessor([-100123]);

        $message = new InternalMessage();
        $message->chatId = -100123;
        $chain = new MessageChain([$message]);

        $result = $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertTrue($result->abortProcessing);
    }

    public function testAllowsMessageFromNonBlockedChat(): void
    {
        $processor = new BlockedChatProcessor([-100123]);

        $message = new InternalMessage();
        $message->chatId = -100456;
        $chain = new MessageChain([$message]);

        $result = $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertFalse($result->abortProcessing);
    }

    public function testEmptyBlockedListAllowsAll(): void
    {
        $processor = new BlockedChatProcessor([]);

        $message = new InternalMessage();
        $message->chatId = -100456;
        $chain = new MessageChain([$message]);

        $result = $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertFalse($result->abortProcessing);
    }

    public function testMultipleBlockedChats(): void
    {
        $processor = new BlockedChatProcessor([-100123, -100456, -100789]);

        $msg1 = new InternalMessage();
        $msg1->chatId = -100123;
        $result1 = $processor->processMessageChain(new MessageChain([$msg1]), $this->createMockCallback());
        $this->assertTrue($result1->abortProcessing);

        $msg2 = new InternalMessage();
        $msg2->chatId = -100456;
        $result2 = $processor->processMessageChain(new MessageChain([$msg2]), $this->createMockCallback());
        $this->assertTrue($result2->abortProcessing);

        $msg3 = new InternalMessage();
        $msg3->chatId = -100999;
        $result3 = $processor->processMessageChain(new MessageChain([$msg3]), $this->createMockCallback());
        $this->assertFalse($result3->abortProcessing);
    }

    public function testPositiveChatIdBlocked(): void
    {
        $processor = new BlockedChatProcessor([12345678]);

        $message = new InternalMessage();
        $message->chatId = 12345678;
        $chain = new MessageChain([$message]);

        $result = $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertTrue($result->abortProcessing);
    }
}
