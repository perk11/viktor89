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
 * Regression guard for the /personalitycard vs /personality command-prefix
 * collision. CommandBasedResponderTrigger matches with str_starts_with and no
 * word boundary, and it MUTATES the message text, so "/personalitycard" would be
 * eaten by "/personality" (leaving "card" for the fallback assistant) unless the
 * longer command is registered first. These tests pin that ordering rule against
 * the real trigger + runner.
 */
#[CoversClass(MessageChainProcessorRunner::class)]
class PersonalityCommandRoutingTest extends TestCase
{
    public function testPersonalitycardIsHandledBeforePersonalityWhenOrderedFirst(): void
    {
        $card = $this->createMock(MessageChainProcessor::class);
        $card->expects($this->once())->method('processMessageChain')
            ->willReturn(new ProcessingResult(null, true));
        $personality = $this->createMock(MessageChainProcessor::class);
        $personality->expects($this->never())->method('processMessageChain');

        $this->runner([
            new CommandBasedResponderTrigger(['/personalitycard', '/pcard'], $card),
            new CommandBasedResponderTrigger(['/personality'], $personality),
        ])->run($this->chain('/personalitycard'), $this->progressCallback());
    }

    public function testPlainPersonalityCommandStillReachesPersonalityProcessor(): void
    {
        $card = $this->createMock(MessageChainProcessor::class);
        $card->expects($this->never())->method('processMessageChain');
        $personality = $this->createMock(MessageChainProcessor::class);
        $personality->expects($this->once())->method('processMessageChain')
            ->willReturn(new ProcessingResult(null, true));

        $this->runner([
            new CommandBasedResponderTrigger(['/personalitycard', '/pcard'], $card),
            new CommandBasedResponderTrigger(['/personality'], $personality),
        ])->run($this->chain('/personality something'), $this->progressCallback());
    }

    public function testPersonalityAliasPcardDoesNotCollide(): void
    {
        $card = $this->createMock(MessageChainProcessor::class);
        $card->expects($this->once())->method('processMessageChain')
            ->willReturn(new ProcessingResult(null, true));
        $personality = $this->createMock(MessageChainProcessor::class);
        $personality->expects($this->never())->method('processMessageChain');

        $this->runner([
            new CommandBasedResponderTrigger(['/personalitycard', '/pcard'], $card),
            new CommandBasedResponderTrigger(['/personality'], $personality),
        ])->run($this->chain('/pcard'), $this->progressCallback());
    }

    /**
     * Documents exactly why the ordering above is required: with the wrong order,
     * /personality's trigger matches "/personalitycard" via str_starts_with,
     * strips the prefix, and hands "card" to the personality responder.
     */
    public function testWrongOrderLetsPersonalityStealPersonalitycard(): void
    {
        $card = $this->createMock(MessageChainProcessor::class);
        $card->expects($this->never())->method('processMessageChain');
        $personality = $this->createMock(MessageChainProcessor::class);
        $personality->expects($this->once())->method('processMessageChain')
            ->willReturn(new ProcessingResult(null, true));

        $this->runner([
            new CommandBasedResponderTrigger(['/personality'], $personality),
            new CommandBasedResponderTrigger(['/personalitycard', '/pcard'], $card),
        ])->run($this->chain('/personalitycard'), $this->progressCallback());
    }

    private function runner(array $processors): MessageChainProcessorRunner
    {
        return new MessageChainProcessorRunner($this->createMock(ProcessingResultExecutor::class), $processors);
    }

    private function chain(string $text): MessageChain
    {
        $message = new InternalMessage();
        $message->messageText = $text;

        return new MessageChain([$message]);
    }

    private function progressCallback(): ProgressUpdateCallback
    {
        return $this->createMock(ProgressUpdateCallback::class);
    }
}
