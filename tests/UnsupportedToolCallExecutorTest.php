<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\Tool\UnsupportedToolCallExecutor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnsupportedToolCallExecutor::class)]
class UnsupportedToolCallExecutorTest extends TestCase
{
    public function testExecuteToolCallThrowsException(): void
    {
        $executor = new UnsupportedToolCallExecutor();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unsupported tool call');

        $executor->executeToolCall(['key' => 'value']);
    }

    public function testExecuteToolCallWithEmptyArguments(): void
    {
        $executor = new UnsupportedToolCallExecutor();

        $this->expectException(\LogicException::class);

        $executor->executeToolCall([]);
    }

    public function testExecuteToolCallWithComplexArguments(): void
    {
        $executor = new UnsupportedToolCallExecutor();

        $this->expectException(\LogicException::class);

        $executor->executeToolCall([
            'nested' => ['array' => 'value'],
            'list' => [1, 2, 3],
        ]);
    }
}
