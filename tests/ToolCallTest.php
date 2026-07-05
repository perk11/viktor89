<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\Tool\ToolCall;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolCall::class)]
class ToolCallTest extends TestCase
{
    public function testConstructorWithRequiredFields(): void
    {
        $call = new ToolCall('call_1', 'get_url', '{"url": "https://example.com"}');

        $this->assertSame('call_1', $call->id);
        $this->assertSame('get_url', $call->name);
        $this->assertSame('{"url": "https://example.com"}', $call->arguments);
        $this->assertNull($call->result);
    }

    public function testConstructorWithResult(): void
    {
        $call = new ToolCall('call_2', 'search', '{"query": "test"}', 'Result found');

        $this->assertSame('call_2', $call->id);
        $this->assertSame('search', $call->name);
        $this->assertSame('{"query": "test"}', $call->arguments);
        $this->assertSame('Result found', $call->result);
    }

    public function testConstructorWithEmptyArguments(): void
    {
        $call = new ToolCall('call_3', 'ping', '');

        $this->assertSame('ping', $call->name);
        $this->assertSame('', $call->arguments);
    }

    public function testConstructorWithEmptyResult(): void
    {
        $call = new ToolCall('call_4', 'ping', '{}', '');

        $this->assertSame('', $call->result);
    }

    public function testPropertiesAreMutable(): void
    {
        $call = new ToolCall('call_1', 'old_name', '{}');

        $call->id = 'call_2';
        $call->name = 'new_name';
        $call->arguments = '{"key": "value"}';
        $call->result = 'done';

        $this->assertSame('call_2', $call->id);
        $this->assertSame('new_name', $call->name);
        $this->assertSame('{"key": "value"}', $call->arguments);
        $this->assertSame('done', $call->result);
    }

    public function testConstructorWithComplexJsonArguments(): void
    {
        $json = '{"tools": [{"name": "a"}, {"name": "b"}], "meta": {"version": 1}}';
        $call = new ToolCall('call_1', 'complex_tool', $json);

        $this->assertSame($json, $call->arguments);
        $decoded = json_decode($call->arguments, true);
        $this->assertCount(2, $decoded['tools']);
    }
}
