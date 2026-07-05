<?php

declare(strict_types=1);

namespace Perk11\Viktor89\Test;

use Perk11\Viktor89\Assistant\CompletionResponse;
use Perk11\Viktor89\Assistant\Tool\ToolCall;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompletionResponse::class)]
class CompletionResponseTest extends TestCase
{
    public function testConstructorWithContentOnly(): void
    {
        $response = new CompletionResponse('Hello world');

        $this->assertSame('Hello world', $response->content);
        $this->assertSame([], $response->toolCalls);
        $this->assertNull($response->reasoning);
    }

    public function testConstructorWithToolCalls(): void
    {
        $toolCall = new ToolCall('call_1', 'get_url', '{"url": "https://example.com"}');
        $response = new CompletionResponse('Checking...', [$toolCall]);

        $this->assertCount(1, $response->toolCalls);
        $this->assertSame($toolCall, $response->toolCalls[0]);
    }

    public function testConstructorWithReasoning(): void
    {
        $response = new CompletionResponse('Answer', [], 'I analyzed the question...');

        $this->assertSame('I analyzed the question...', $response->reasoning);
    }

    public function testConstructorWithAllFields(): void
    {
        $toolCall = new ToolCall('call_1', 'search', '{"query": "test"}');
        $response = new CompletionResponse('Result', [$toolCall], 'Found it');

        $this->assertSame('Result', $response->content);
        $this->assertCount(1, $response->toolCalls);
        $this->assertSame('Found it', $response->reasoning);
    }

    public function testPropertiesAreReadonly(): void
    {
        $response = new CompletionResponse('Test');

        $this->expectException(\Error::class);

        $response->content = 'Changed';
    }

    public function testEmptyContentIsAllowed(): void
    {
        $response = new CompletionResponse('');

        $this->assertSame('', $response->content);
    }

    public function testMultipleToolCalls(): void
    {
        $calls = [
            new ToolCall('call_1', 'tool_a', '{}'),
            new ToolCall('call_2', 'tool_b', '{}'),
            new ToolCall('call_3', 'tool_c', '{}'),
        ];
        $response = new CompletionResponse('Multi', $calls);

        $this->assertCount(3, $response->toolCalls);
    }
}
