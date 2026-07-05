<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\Tool\UnsupportedMessageAwareToolCallExecutor;
use Perk11\Viktor89\InternalMessage;
use Perk11\Viktor89\MessageChain;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnsupportedMessageAwareToolCallExecutor::class)]
class UnsupportedMessageAwareToolCallExecutorTest extends TestCase
{
    public function testExecuteToolCallThrowsException(): void
    {
        $executor = new UnsupportedMessageAwareToolCallExecutor();
        $message = new InternalMessage();
        $chain = new MessageChain([$message]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unsupported tool call');

        $executor->executeToolCall(['key' => 'value'], $chain);
    }

    public function testExecuteToolCallWithEmptyArguments(): void
    {
        $executor = new UnsupportedMessageAwareToolCallExecutor();
        $message = new InternalMessage();
        $chain = new MessageChain([$message]);

        $this->expectException(\LogicException::class);

        $executor->executeToolCall([], $chain);
    }
}
