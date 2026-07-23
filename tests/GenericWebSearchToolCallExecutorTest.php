<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\Tool\GenericWebSearchToolCallExecutor;
use Perk11\Viktor89\Assistant\Tool\ToolCallExecutorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GenericWebSearchToolCallExecutor::class)]
class GenericWebSearchToolCallExecutorTest extends TestCase
{
    public function testReturnsResultFromFirstSuccessfulTool(): void
    {
        $first = $this->createMock(ToolCallExecutorInterface::class);
        $first->expects($this->once())->method('executeToolCall')
            ->willReturn(['results' => [['title' => 'first']]]);

        $second = $this->createMock(ToolCallExecutorInterface::class);
        $second->expects($this->never())->method('executeToolCall');

        $executor = new GenericWebSearchToolCallExecutor([$first, $second], logger: new \Psr\Log\NullLogger());

        $this->assertSame(
            ['results' => [['title' => 'first']]],
            $executor->executeToolCall(['query' => 'x']),
        );
    }

    public function testFallsBackToNextToolWhenFirstThrows(): void
    {
        $first = $this->createMock(ToolCallExecutorInterface::class);
        $first->expects($this->once())->method('executeToolCall')
            ->willThrowException(new \RuntimeException('provider down'));

        $second = $this->createMock(ToolCallExecutorInterface::class);
        $second->expects($this->once())->method('executeToolCall')
            ->willReturn(['results' => [['title' => 'second']]]);

        $executor = new GenericWebSearchToolCallExecutor([$first, $second], logger: new \Psr\Log\NullLogger());

        $this->assertSame(
            ['results' => [['title' => 'second']]],
            $executor->executeToolCall(['query' => 'x']),
        );
    }

    public function testFallsBackAcrossMultipleFailingTools(): void
    {
        $first = $this->createMock(ToolCallExecutorInterface::class);
        $first->method('executeToolCall')
            ->willThrowException(new \RuntimeException('one'));

        $second = $this->createMock(ToolCallExecutorInterface::class);
        $second->method('executeToolCall')
            ->willThrowException(new \RuntimeException('two'));

        $third = $this->createMock(ToolCallExecutorInterface::class);
        $third->expects($this->once())->method('executeToolCall')
            ->willReturn(['results' => [['title' => 'third']]]);

        $executor = new GenericWebSearchToolCallExecutor([$first, $second, $third], logger: new \Psr\Log\NullLogger());

        $this->assertSame(
            ['results' => [['title' => 'third']]],
            $executor->executeToolCall(['query' => 'x']),
        );
    }

    public function testThrowsAggregateExceptionWhenAllToolsFail(): void
    {
        $first = $this->createMock(ToolCallExecutorInterface::class);
        $first->method('executeToolCall')
            ->willThrowException(new \RuntimeException('one failed'));

        $second = $this->createMock(ToolCallExecutorInterface::class);
        $second->method('executeToolCall')
            ->willThrowException(new \RuntimeException('two failed'));

        $executor = new GenericWebSearchToolCallExecutor([$first, $second], logger: new \Psr\Log\NullLogger());

        try {
            $executor->executeToolCall(['query' => 'x']);
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $exception) {
            $this->assertStringStartsWith('All 2 web search tools failed:', $exception->getMessage());
            $this->assertStringContainsString('1. one failed', $exception->getMessage());
            $this->assertStringContainsString('2. two failed', $exception->getMessage());
            $this->assertSame('one failed', $exception->getPrevious()->getMessage());
        }
    }

    public function testRejectsEmptyToolList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one search tool must be provided');
        new GenericWebSearchToolCallExecutor([], logger: new \Psr\Log\NullLogger());
    }

    public function testRejectsNonToolExecutorEntries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement');
        /** @phpstan-ignore-next-line intentionally invalid input */
        new GenericWebSearchToolCallExecutor(['not-a-tool'], logger: new \Psr\Log\NullLogger());
    }

    public function testPassesArgumentsThroughToUnderlyingTool(): void
    {
        $first = $this->createMock(ToolCallExecutorInterface::class);
        $first->expects($this->once())->method('executeToolCall')
            ->with(['query' => 'search term', 'max_results' => 7])
            ->willReturn(['results' => []]);

        $executor = new GenericWebSearchToolCallExecutor([$first], logger: new \Psr\Log\NullLogger());

        $executor->executeToolCall(['query' => 'search term', 'max_results' => 7]);
    }

    public function testImplementsToolCallExecutorInterface(): void
    {
        $first = $this->createMock(ToolCallExecutorInterface::class);
        $executor = new GenericWebSearchToolCallExecutor([$first], logger: new \Psr\Log\NullLogger());

        $this->assertInstanceOf(ToolCallExecutorInterface::class, $executor);
    }
}
