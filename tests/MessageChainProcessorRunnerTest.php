<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use Perk11\Viktor89\MessageChainProcessorRunner;
use Perk11\Viktor89\ProcessingResult;
use Perk11\Viktor89\ProcessingResultExecutor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageChainProcessorRunner::class)]
class MessageChainProcessorRunnerTest extends TestCase
{
    private function createMockCallback(): \Perk11\Viktor89\IPC\ProgressUpdateCallback
    {
        return $this->createMock(\Perk11\Viktor89\IPC\ProgressUpdateCallback::class);
    }

    private function createMockExecutor(): ProcessingResultExecutor
    {
        return $this->createMock(ProcessingResultExecutor::class);
    }

    public function testRunsProcessorsInOrder(): void
    {
        $callOrder = [];
        $processor1 = $this->createMock(\Perk11\Viktor89\MessageChainProcessor::class);
        $processor1->method('processMessageChain')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 1;
                return new ProcessingResult(null, false);
            });

        $processor2 = $this->createMock(\Perk11\Viktor89\MessageChainProcessor::class);
        $processor2->method('processMessageChain')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 2;
                return new ProcessingResult(null, true);
            });

        $executor = $this->createMockExecutor();
        $runner = new MessageChainProcessorRunner($executor, [$processor1, $processor2]);

        $message = new InternalMessage();
        $message->messageText = 'hello';
        $chain = new MessageChain([$message]);

        $runner->run($chain, $this->createMockCallback());

        $this->assertSame([1, 2], $callOrder);
    }

    public function testStopsWhenProcessorAborts(): void
    {
        $callOrder = [];
        $processor1 = $this->createMock(\Perk11\Viktor89\MessageChainProcessor::class);
        $processor1->method('processMessageChain')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 1;
                return new ProcessingResult(null, true);
            });

        $processor2 = $this->createMock(\Perk11\Viktor89\MessageChainProcessor::class);
        $processor2->expects($this->never())->method('processMessageChain');

        $executor = $this->createMockExecutor();
        $runner = new MessageChainProcessorRunner($executor, [$processor1, $processor2]);

        $message = new InternalMessage();
        $message->messageText = 'hello';
        $chain = new MessageChain([$message]);

        $runner->run($chain, $this->createMockCallback());

        $this->assertSame([1], $callOrder);
    }

    public function testStopsWhenAbortIsTrue(): void
    {
        $callOrder = [];
        $processor = $this->createMock(\Perk11\Viktor89\MessageChainProcessor::class);
        $processor->method('processMessageChain')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'called';
                return new ProcessingResult(null, true);
            });

        $executor = $this->createMockExecutor();
        $runner = new MessageChainProcessorRunner($executor, [$processor]);

        $message = new InternalMessage();
        $message->messageText = 'test';
        $chain = new MessageChain([$message]);

        $runner->run($chain, $this->createMockCallback());

        $this->assertSame(['called'], $callOrder);
    }
}
