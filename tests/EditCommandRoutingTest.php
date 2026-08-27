<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\IPC\ProgressUpdateCallback;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessor;
use Perk11\Viktor89\MessageChainProcessorRunner;
use Perk11\Viktor89\PreResponseProcessor\CommandBasedResponderTrigger;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\ProcessingResultExecutor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for the /edit command wiring. /edit must be registered
 * without a trailing space: CommandBasedResponderTrigger matches the command
 * followed by a word boundary, and a trailing space in the registration makes
 * the pattern require a word character right after the space — so prompts
 * starting with a newline, another space or punctuation were silently ignored.
 * Also pins that /edit does not collide with the shorter /e and longer
 * /editmodel commands registered alongside it in ProcessMessageTask.
 */
#[CoversClass(MessageChainProcessorRunner::class)]
#[CoversClass(CommandBasedResponderTrigger::class)]
class EditCommandRoutingTest extends TestCase
{
    /** @var string[] prompts received by the /e responder */
    private array $ePrompts = [];
    /** @var string[] prompts received by the /edit responder */
    private array $editPrompts = [];

    private function makeRunner(): MessageChainProcessorRunner
    {
        $eResponder = $this->createMock(MessageChainProcessor::class);
        $eResponder->method('processMessageChain')
            ->willReturnCallback(function (MessageChain $chain) {
                $this->ePrompts[] = $chain->last()->messageText;

                return new ProcessingResult(null, true);
            });

        $editResponder = $this->createMock(MessageChainProcessor::class);
        $editResponder->method('processMessageChain')
            ->willReturnCallback(function (MessageChain $chain) {
                $this->editPrompts[] = $chain->last()->messageText;

                return new ProcessingResult(null, true);
            });

        // Registration order mirrors ProcessMessageTask: /e before /edit.
        $processors = [
            new CommandBasedResponderTrigger(['/e'], $eResponder, logger: new \Psr\Log\NullLogger()),
            new CommandBasedResponderTrigger(['/edit'], $editResponder, logger: new \Psr\Log\NullLogger()),
        ];

        return new MessageChainProcessorRunner(
            $this->createMock(ProcessingResultExecutor::class),
            $processors,
            logger: new \Psr\Log\NullLogger(),
        );
    }

    private function runMessage(string $text): void
    {
        $this->makeRunner()->run(
            new MessageChain([self::makeMessage($text)]),
            $this->createMock(ProgressUpdateCallback::class),
        );
    }

    private function assertEditHandled(string $text, string $expectedPrompt): void
    {
        $this->runMessage($text);

        $this->assertSame([$expectedPrompt], $this->editPrompts, '/edit must handle the message and strip the command from the prompt');
        $this->assertSame([], $this->ePrompts, '/e must not swallow /edit messages');
    }

    public function testEditWithSingleSpaceBeforePromptIsHandled(): void
    {
        $this->assertEditHandled('/edit make it blue', 'make it blue');
    }

    public function testEditWithNewlineBeforePromptIsHandled(): void
    {
        $this->assertEditHandled("/edit\nmake it blue", 'make it blue');
    }

    public function testEditWithDoubleSpaceBeforePromptIsHandled(): void
    {
        $this->assertEditHandled('/edit  make it blue', 'make it blue');
    }

    public function testEditWithPunctuationAfterSpaceIsHandled(): void
    {
        $this->assertEditHandled('/edit "make it blue"', '"make it blue"');
    }

    public function testEditWithoutPromptIsHandled(): void
    {
        $this->assertEditHandled('/edit', '');
    }

    public function testShorterCommandStillHandledByItsOwnResponder(): void
    {
        $this->runMessage('/e make it red');

        $this->assertSame(['make it red'], $this->ePrompts);
        $this->assertSame([], $this->editPrompts, '/edit must not swallow /e messages');
    }

    public function testEditModelCommandIsNotSwallowedByEdit(): void
    {
        $this->runMessage('/editmodel flux');

        $this->assertSame([], $this->editPrompts, '/edit must not match /editmodel');
        $this->assertSame([], $this->ePrompts, '/e must not match /editmodel');
    }

    public function testEditingIsNotEdit(): void
    {
        $this->runMessage('/editing something');

        $this->assertSame([], $this->editPrompts, '/edit must not match /editing');
    }

    public function testEditAndEInTheSameMessageAreBothProcessed(): void
    {
        $this->runMessage("/edit make it blue\n/e make it red");

        $this->assertSame(['make it blue'], $this->editPrompts);
        $this->assertSame(['make it red'], $this->ePrompts);
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
