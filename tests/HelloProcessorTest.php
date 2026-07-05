<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\PreResponseProcessor\HelloProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HelloProcessor::class)]
class HelloProcessorTest extends TestCase
{
    private function createMockCallback(): \Perk11\Viktor89\IPC\ProgressUpdateCallback
    {
        return $this->createMock(\Perk11\Viktor89\IPC\ProgressUpdateCallback::class);
    }

    public function testDoesNotTriggerForNonAllowedUsers(): void
    {
        $processor = new HelloProcessor();

        $message = new InternalMessage();
        $message->userId = 999;
        $message->messageText = 'дарова';
        $chain = new MessageChain([$message]);

        $result = $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertFalse($result->abortProcessing);
    }

    public function testTriggersForAllowedUserWithKnownPhrase(): void
    {
        $processor = new HelloProcessor();

        $message = new InternalMessage();
        $message->userId = 5461833561;
        $message->messageText = 'дарова';
        $chain = new MessageChain([$message]);

        $result = $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertTrue($result->abortProcessing);
    }

    public function testTriggersOnAllKnownPhrases(): void
    {
        $processor = new HelloProcessor();

        $phrases = ['дарова', 'даровч', 'привет', 'хау'];

        foreach ($phrases as $phrase) {
            $message = new InternalMessage();
            $message->userId = 7010262656;
            $message->messageText = $phrase;
            $chain = new MessageChain([$message]);

            $result = $processor->processMessageChain($chain, $this->createMockCallback());

            $this->assertTrue($result->abortProcessing, "Should trigger on: $phrase");
        }
    }

    public function testDoesNotTriggerForAllowedUserWithUnknownPhrase(): void
    {
        $processor = new HelloProcessor();

        $message = new InternalMessage();
        $message->userId = 5461833561;
        $message->messageText = 'прощай';
        $chain = new MessageChain([$message]);

        $result = $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertFalse($result->abortProcessing);
    }

    public function testCaseInsensitiveMatching(): void
    {
        $processor = new HelloProcessor();

        $message = new InternalMessage();
        $message->userId = 5461833561;
        $message->messageText = 'ПРИВЕТ';
        $chain = new MessageChain([$message]);

        $result = $processor->processMessageChain($chain, $this->createMockCallback());

        $this->assertTrue($result->abortProcessing);
    }
}
