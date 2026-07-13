<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\MessageChainProcessorRunner;
use Perk11\Viktor89\PreResponseProcessor\CommandBasedResponderTrigger;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\ProcessingResultExecutor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Guards the ordering contract relied on by ProcessMessageTask: "/emojiart"
 * must be registered before "/e", because CommandBasedResponderTrigger matches
 * commands with str_starts_with and "/e" is a prefix of "/emojiart".
 */
#[CoversClass(MessageChainProcessorRunner::class)]
#[CoversClass(CommandBasedResponderTrigger::class)]
class EmojiArtCommandDisambiguationTest extends TestCase
{
    public function testEmojiArtWinsOverPrefixingECommandWhenOrderedFirst(): void
    {
        $executor = $this->createStub(ProcessingResultExecutor::class);
        $executor->method('execute');

        $emojiArtResponder = $this->createMock(MessageChainProcessor::class);
        $emojiArtResponder->expects($this->once())
            ->method('processMessageChain')
            ->willReturn(new ProcessingResult(null, true));

        $eResponder = $this->createMock(MessageChainProcessor::class);
        $eResponder->expects($this->never())->method('processMessageChain');

        $runner = new MessageChainProcessorRunner($executor, [
            new CommandBasedResponderTrigger(['/emojiart'], $emojiArtResponder),
            new CommandBasedResponderTrigger(['/e'], $eResponder),
        ]);

        $runner->run(
            new MessageChain([self::makeMessage('/emojiart')]),
            $this->createStub(ProgressUpdateCallback::class),
        );
    }

    public function testPlainECommandStillRoutesToEProcessor(): void
    {
        $executor = $this->createStub(ProcessingResultExecutor::class);
        $executor->method('execute');

        $emojiArtResponder = $this->createMock(MessageChainProcessor::class);
        $emojiArtResponder->expects($this->never())->method('processMessageChain');

        $eResponder = $this->createMock(MessageChainProcessor::class);
        $eResponder->expects($this->once())
            ->method('processMessageChain')
            ->willReturn(new ProcessingResult(null, true));

        $runner = new MessageChainProcessorRunner($executor, [
            new CommandBasedResponderTrigger(['/emojiart'], $emojiArtResponder),
            new CommandBasedResponderTrigger(['/e'], $eResponder),
        ]);

        $runner->run(
            new MessageChain([self::makeMessage('/e a red cat')]),
            $this->createStub(ProgressUpdateCallback::class),
        );
    }

    public function testEmojiArtWithWidthArgumentIsNotSplit(): void
    {
        $executor = $this->createStub(ProcessingResultExecutor::class);
        $executor->method('execute');

        $emojiArtResponder = $this->createMock(MessageChainProcessor::class);
        $emojiArtResponder->expects($this->once())
            ->method('processMessageChain')
            ->willReturnCallback(function (MessageChain $chain): ProcessingResult {
                // The whole "40" survives as the argument, none of it leaks to /e.
                $this->assertSame('40', $chain->last()->messageText);
                return new ProcessingResult(null, true);
            });

        $eResponder = $this->createMock(MessageChainProcessor::class);
        $eResponder->expects($this->never())->method('processMessageChain');

        $runner = new MessageChainProcessorRunner($executor, [
            new CommandBasedResponderTrigger(['/emojiart'], $emojiArtResponder),
            new CommandBasedResponderTrigger(['/e'], $eResponder),
        ]);

        $runner->run(
            new MessageChain([self::makeMessage('/emojiart 40')]),
            $this->createStub(ProgressUpdateCallback::class),
        );
    }

    private static function makeMessage(string $text): InternalMessage
    {
        $message = new InternalMessage();
        $message->id = random_int(1, 100000);
        $message->chatId = -100123;
        $message->userId = 12345;
        $message->userName = 'User';
        $message->messageText = $text;
        $message->type = 'text';
        $message->date = time();
        return $message;
    }
}
