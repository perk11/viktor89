<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\AllowedChatProcessor;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AllowedChatProcessor::class)]
class AllowedChatProcessorTest extends TestCase
{
    private function createMockCallback(): \Perk11\Viktor89\IPC\ProgressUpdateCallback
    {
        return $this->createMock(\Perk11\Viktor89\IPC\ProgressUpdateCallback::class);
    }

    public function testAllowsMessageFromAllowedChat(): void
    {
        $processor = new AllowedChatProcessor([-100123]);

        $message = new InternalMessage();
        $message->chatId = -100123;
        $message->messageText = 'hello';
        $chain = new MessageChain([$message]);

        $result = $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertFalse($result->abortProcessing);
    }

    public function testBlocksMessageFromNotAllowedChat(): void
    {
        $processor = new AllowedChatProcessor([-100123]);

        $message = new InternalMessage();
        $message->chatId = -100456;
        $message->messageText = 'hello';
        $chain = new MessageChain([$message]);

        $result = $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertTrue($result->abortProcessing);
        $this->assertNotNull($result->response);
    }

    public function testDoesNotBlockCommands(): void
    {
        $processor = new AllowedChatProcessor([-100123]);

        $message = new InternalMessage();
        $message->chatId = -100456;
        $message->messageText = '/image test';
        $chain = new MessageChain([$message]);

        $result = $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertFalse($result->abortProcessing);
    }

    public function testEmptyAllowedListBlocksAllNonCommands(): void
    {
        $processor = new AllowedChatProcessor([]);

        $message = new InternalMessage();
        $message->chatId = -100456;
        $message->messageText = 'hello';
        $chain = new MessageChain([$message]);

        $result = $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertTrue($result->abortProcessing);
    }

    public function testResponseMessageContainsErrorMessage(): void
    {
        $processor = new AllowedChatProcessor([-100123]);

        $message = new InternalMessage();
        $message->chatId = -100456;
        $message->id = 99;
        $message->messageText = 'test';
        $chain = new MessageChain([$message]);

        $result = $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertNotNull($result->response);
        $this->assertStringContainsString('отключена', $result->response->messageText);
    }
}
