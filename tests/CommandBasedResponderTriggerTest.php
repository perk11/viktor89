<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Exception;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\PreResponseProcessor\CommandBasedResponderTrigger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommandBasedResponderTrigger::class)]
class CommandBasedResponderTriggerTest extends TestCase
{
    public function testMatchingTriggerReturnsResponderResult(): void
    {
        $responder = $this->createMock(MessageChainProcessor::class);
        $responder->expects($this->once())
            ->method('processMessageChain')
            ->willReturn(new ProcessingResult(
                self::makeMessage('Bot', 'Hello!'),
                true,
            ));

        $callback = $this->createMock(ProgressUpdateCallback::class);

        $trigger = new CommandBasedResponderTrigger(
            ['/hello'],
            $responder,
         logger: new \Psr\Log\NullLogger());

        $chain = new MessageChain([self::makeMessage('User', '/hello world')]);
        $result = $trigger->processMessageChain($chain, $callback);

        $this->assertInstanceOf(ProcessingResult::class, $result);
        // Command text should be trimmed from the last message
        $this->assertSame('world', $chain->last()->messageText);
    }

    public function testMultipleTriggersUseFirstMatch(): void
    {
        $responder = $this->createMock(MessageChainProcessor::class);
        $responder->expects($this->once())
            ->method('processMessageChain')
            ->willReturn(new ProcessingResult(null, true));

        $callback = $this->createMock(ProgressUpdateCallback::class);

        $trigger = new CommandBasedResponderTrigger(
            ['/img', '/video'],
            $responder,
         logger: new \Psr\Log\NullLogger());

        $chain = new MessageChain([self::makeMessage('User', '/img a cat')]);
        $trigger->processMessageChain($chain, $callback);

        $this->assertSame('a cat', $chain->last()->messageText);
    }

    public function testNonMatchingTriggerReturnsNullResult(): void
    {
        $responder = $this->createMock(MessageChainProcessor::class);
        $responder->expects($this->never())
            ->method('processMessageChain');

        $callback = $this->createMock(ProgressUpdateCallback::class);

        $trigger = new CommandBasedResponderTrigger(
            ['/hello'],
            $responder,
         logger: new \Psr\Log\NullLogger());

        $chain = new MessageChain([self::makeMessage('User', 'Just a normal message')]);
        $result = $trigger->processMessageChain($chain, $callback);

        $this->assertNull($result->response);
        $this->assertFalse($result->abortProcessing);
    }

    public function testNonMatchingTriggerDoesNotModifyChain(): void
    {
        $responder = $this->createMock(MessageChainProcessor::class);
        $responder->expects($this->never())
            ->method('processMessageChain');

        $callback = $this->createMock(ProgressUpdateCallback::class);

        $trigger = new CommandBasedResponderTrigger(
            ['/hello'],
            $responder,
         logger: new \Psr\Log\NullLogger());

        $chain = new MessageChain([self::makeMessage('User', 'no command here')]);
        $trigger->processMessageChain($chain, $callback);

        $this->assertSame('no command here', $chain->last()->messageText);
    }

    public function testResponderExceptionReturnsThinkingReaction(): void
    {
        $responder = $this->createMock(MessageChainProcessor::class);
        $responder->expects($this->once())
            ->method('processMessageChain')
            ->willThrowException(new Exception('Something went wrong'));

        $callback = $this->createMock(ProgressUpdateCallback::class);

        $trigger = new CommandBasedResponderTrigger(
            ['/hello'],
            $responder,
         logger: new \Psr\Log\NullLogger());

        $chain = new MessageChain([self::makeMessage('User', '/hello')]);
        $result = $trigger->processMessageChain($chain, $callback);

        $this->assertNull($result->response);
        $this->assertTrue($result->abortProcessing);
        $this->assertSame('🤔', $result->reaction);
    }

    public function testGetTriggeringCommandsReturnsConfiguredCommands(): void
    {
        $responder = $this->createMock(MessageChainProcessor::class);

        $trigger = new CommandBasedResponderTrigger(
            ['/img', '/video', '/sing'],
            $responder,
         logger: new \Psr\Log\NullLogger());

        $this->assertSame(['/img', '/video', '/sing'], $trigger->getTriggeringCommands());
    }

    public function testAlsoTriggerOnResponsesRemovesCommandFromAllMessages(): void
    {
        $responder = $this->createMock(MessageChainProcessor::class);
        $responder->expects($this->once())
            ->method('processMessageChain')
            ->willReturn(new ProcessingResult(null, true));

        $callback = $this->createMock(ProgressUpdateCallback::class);

        $trigger = new CommandBasedResponderTrigger(
            ['/img'],
            $responder,
            logger: new \Psr\Log\NullLogger(),
            alsoTriggerOnResponsesToThisUserIdIfCommandIsInChain: 12345,
        );

        $botMessage = self::makeMessage('Bot', '/img a cat');
        $botMessage->userId = 12345;

        $userMessage = self::makeMessage('User', 'Yes please');
        $userMessage->userId = 67890;

        $chain = new MessageChain([$botMessage, $userMessage]);
        $trigger->processMessageChain($chain, $callback);

        // Command should be removed from bot message in chain
        $this->assertSame('a cat', $chain->getMessages()[0]->messageText);
    }

    public function testAlsoTriggerOnResponsesSkipsIfPreviousUserIdDoesNotMatch(): void
    {
        $responder = $this->createMock(MessageChainProcessor::class);
        $responder->expects($this->never())
            ->method('processMessageChain');

        $callback = $this->createMock(ProgressUpdateCallback::class);

        $trigger = new CommandBasedResponderTrigger(
            ['/img'],
            $responder,
            logger: new \Psr\Log\NullLogger(),
            alsoTriggerOnResponsesToThisUserIdIfCommandIsInChain: 12345,
        );

        $botMessage = self::makeMessage('Bot', '/img a cat');
        $botMessage->userId = 99999; // different user ID

        $userMessage = self::makeMessage('User', 'Yes please');

        $chain = new MessageChain([$botMessage, $userMessage]);
        $result = $trigger->processMessageChain($chain, $callback);

        $this->assertNull($result->response);
        $this->assertFalse($result->abortProcessing);
    }

    public function testCommandOnlyWithoutArgumentsTrimsCorrectly(): void
    {
        $responder = $this->createMock(MessageChainProcessor::class);
        $responder->expects($this->once())
            ->method('processMessageChain')
            ->willReturn(new ProcessingResult(null, true));

        $callback = $this->createMock(ProgressUpdateCallback::class);

        $trigger = new CommandBasedResponderTrigger(
            ['/status'],
            $responder,
         logger: new \Psr\Log\NullLogger());

        $chain = new MessageChain([self::makeMessage('User', '/status')]);
        $trigger->processMessageChain($chain, $callback);

        $this->assertSame('', $chain->last()->messageText);
    }

    private static function makeMessage(string $userName, string $text): InternalMessage
    {
        $message = new InternalMessage();
        $message->id = random_int(1, 100000);
        $message->chatId = -100123;
        $message->userId = 12345;
        $message->userName = $userName;
        $message->messageText = $text;
        $message->type = 'text';
        $message->date = time();
        return $message;
    }
}
