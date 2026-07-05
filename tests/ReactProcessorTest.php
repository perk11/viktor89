<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\FixedValuePreferenceProvider;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\PreResponseProcessor\ReactProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReactProcessor::class)]
class ReactProcessorTest extends TestCase
{
    private function createMockCallback(): \Perk11\Viktor89\IPC\ProgressUpdateCallback
    {
        return $this->createMock(\Perk11\Viktor89\IPC\ProgressUpdateCallback::class);
    }

    public function testReturnsReactionWhenPreferenceIsSet(): void
    {
        $enabled = new FixedValuePreferenceProvider('enabled');
        $processor = new ReactProcessor($enabled, '👍');

        $message = new InternalMessage();
        $message->userId = 123;
        $chain = new MessageChain([$message]);

        $result = $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertSame('👍', $result->reaction);
        $this->assertSame($message, $result->messageToReactTo);
        $this->assertFalse($result->abortProcessing);
    }

    public function testReturnsNoReactionWhenPreferenceIsNull(): void
    {
        $enabled = new FixedValuePreferenceProvider(null);
        $processor = new ReactProcessor($enabled, '👍');

        $message = new InternalMessage();
        $message->userId = 456;
        $chain = new MessageChain([$message]);

        $result = $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertNull($result->reaction);
        $this->assertFalse($result->abortProcessing);
    }

    public function testReturnsCorrectEmoji(): void
    {
        $enabled = new FixedValuePreferenceProvider('yes');
        $processor = new ReactProcessor($enabled, '🔥');

        $message = new InternalMessage();
        $message->userId = 1;
        $chain = new MessageChain([$message]);

        $result = $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertSame('🔥', $result->reaction);
    }

    public function testDoesNotAbortProcessing(): void
    {
        $enabled = new FixedValuePreferenceProvider('value');
        $processor = new ReactProcessor($enabled, '❤️');

        $message = new InternalMessage();
        $message->userId = 1;
        $chain = new MessageChain([$message]);

        $result = $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertFalse($result->abortProcessing);
    }
}
